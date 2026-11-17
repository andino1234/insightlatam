<?php
/**
 * Panel de blog Insight LATAM — funciones comunes.
 * No necesita base de datos: guarda todo en archivos dentro de /panel-blog/data/.
 *
 * @author Ariel Geovanny Andino Chinchilla
 * @link   https://github.com/andino1234
 */
declare(strict_types=1);

define('PANEL_DIR', __DIR__);
define('SITE_DIR', dirname(__DIR__));
define('DATA_DIR', PANEL_DIR . '/data');
define('SITE_URL', 'https://insightlatam.com');
define('UPLOAD_DIR', SITE_DIR . '/assets/images/blog/posts');
define('UPLOAD_URL', 'assets/images/blog/posts');
define('DEFAULT_COVER', 'assets/images/home/consultoria-empresarial.jpg');
define('MAX_ATTEMPTS', 5);
define('LOCK_MINUTES', 15);

/* ---------- Cabeceras de seguridad ---------- */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

/* ---------- Sesión ---------- */
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('ilpanel');
session_set_cookie_params(['lifetime' => 0, 'path' => '/panel-blog/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

/* ---------- Archivos de datos (protegidos con <?php exit; ?>) ---------- */
const GUARD = "<?php exit; ?>\n";

function data_read(string $name, $default) {
    $f = DATA_DIR . "/$name.php";
    if (!is_file($f)) return $default;
    $raw = (string) file_get_contents($f);
    if (strpos($raw, GUARD) === 0) $raw = substr($raw, strlen(GUARD));
    $v = json_decode($raw, true);
    return $v === null ? $default : $v;
}

function data_write(string $name, $value): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    atomic_write(DATA_DIR . "/$name.php", GUARD . $json);
}

function atomic_write(string $file, string $content): void {
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir ' . basename($file) . '. Revisa los permisos de la carpeta en aaPanel.');
    }
    rename($tmp, $file);
}

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Autenticación ---------- */
function auth_config(): array { return data_read('auth', []); }

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function login_locked(): int {
    $a = data_read('attempts', []);
    $r = $a[client_ip()] ?? null;
    if ($r && $r['count'] >= MAX_ATTEMPTS && time() - $r['last'] < LOCK_MINUTES * 60) {
        return (int) ceil((LOCK_MINUTES * 60 - (time() - $r['last'])) / 60);
    }
    return 0;
}

function login_attempt(string $user, string $pass): bool {
    $cfg = auth_config();
    $ok = isset($cfg['user'], $cfg['hash'])
        && hash_equals(strtolower($cfg['user']), strtolower(trim($user)))
        && password_verify($pass, $cfg['hash']);
    $a = data_read('attempts', []);
    $ip = client_ip();
    // limpia registros viejos
    foreach ($a as $k => $r) if (time() - $r['last'] > 86400) unset($a[$k]);
    if ($ok) {
        unset($a[$ip]);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['since'] = time();
    } else {
        $r = $a[$ip] ?? ['count' => 0, 'last' => 0];
        if (time() - $r['last'] > LOCK_MINUTES * 60) $r['count'] = 0;
        $r['count']++; $r['last'] = time();
        $a[$ip] = $r;
        usleep(400000);
    }
    data_write('attempts', $a);
    return $ok;
}

function is_logged_in(): bool {
    // la sesión expira tras 4 horas
    return !empty($_SESSION['admin']) && time() - ($_SESSION['since'] ?? 0) < 4 * 3600;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('La sesión expiró. Vuelve atrás y recarga la página.');
    }
}

/* ---------- Artículos ---------- */
function posts(): array {
    $p = data_read('posts', []);
    usort($p, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($b['created'] ?? '', $a['created'] ?? ''));
    return $p;
}

function find_post(string $slug): ?array {
    foreach (posts() as $p) if ($p['slug'] === $slug) return $p;
    return null;
}

function save_posts(array $posts): void { data_write('posts', array_values($posts)); }

function slugify(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    $s = substr($s, 0, 70);
    return trim($s, '-') ?: 'articulo';
}

function file_for(string $slug): string { return 'blog-' . $slug . '.html'; }

/** Limpia el HTML del editor: solo deja etiquetas de texto seguras. */
function sanitize_html(string $html): string {
    $allowed = ['p','h2','h3','h4','ul','ol','li','strong','b','em','i','u','a','blockquote','br'];
    if (!class_exists('DOMDocument')) {
        return strip_tags($html, '<' . implode('><', $allowed) . '>');
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('root');
    if (!$root) return '';
    $clean = function (DOMNode $node) use (&$clean, $allowed, $doc) {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script','style','iframe','object','embed','form','input','button','textarea','select','svg','math','template'], true)) {
                    $node->removeChild($child); continue;
                }
                $clean($child);
                if ($tag === 'h1') { $tag = 'h2'; }
                if ($tag === 'div' || $tag === 'span' || $tag === 'font' || $tag === 'section' || !in_array($tag, array_merge($allowed, ['h1']), true)) {
                    // conserva el texto, quita la etiqueta
                    if ($tag === 'div' || $tag === 'section') {
                        $p = $doc->createElement('p');
                        while ($child->firstChild) $p->appendChild($child->firstChild);
                        $node->replaceChild($p, $child);
                        continue;
                    }
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child); continue;
                }
                $new = $doc->createElement($tag);
                while ($child->firstChild) $new->appendChild($child->firstChild);
                if ($tag === 'a') {
                    $href = trim($child->getAttribute('href'));
                    if (preg_match('#^(https?://|mailto:|tel:|/|[a-z0-9_-]+\.html)#i', $href)) {
                        $new->setAttribute('href', $href);
                        if (preg_match('#^https?://#i', $href) && stripos($href, 'insightlatam.com') === false) {
                            $new->setAttribute('target', '_blank');
                            $new->setAttribute('rel', 'noopener');
                        }
                    }
                }
                $node->replaceChild($new, $child);
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    };
    $clean($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    // quita párrafos vacíos
    $out = preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#u', '', $out);
    return trim((string) $out);
}

function reading_minutes(string $html): int {
    $words = str_word_count(strip_tags($html), 0, 'áéíóúüñÁÉÍÓÚÜÑ');
    return max(1, (int) round($words / 200));
}

function fecha_larga(string $ymd): string {
    $m = ['', 'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    [$y, $mo, $d] = array_map('intval', explode('-', $ymd));
    return "$d de {$m[$mo]} de $y";
}

function fecha_corta(string $ymd): string {
    $m = ['', 'ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    [$y, $mo, $d] = array_map('intval', explode('-', $ymd));
    return "$d {$m[$mo]} $y";
}

/** Guarda una imagen subida: la valida, la achica a 1600px y la vuelve a codificar. */
function save_cover(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('La imagen no se pudo subir. Prueba con una de menos de 8 MB.');
    if ($file['size'] > 8 * 1024 * 1024) throw new RuntimeException('La imagen pesa más de 8 MB.');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('La imagen debe ser JPG, PNG o WEBP.');
    }
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.jpg';
    if (function_exists('imagecreatefromstring')) {
        $src = imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$src) throw new RuntimeException('No se pudo leer la imagen.');
        $w = imagesx($src); $hgt = imagesy($src);
        $nw = min(1600, $w); $nh = (int) round($hgt * $nw / $w);
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hgt);
        imagejpeg($dst, UPLOAD_DIR . '/' . $name, 82);
        imagedestroy($src); imagedestroy($dst);
    } else {
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $name = preg_replace('/\.jpg$/', ".$ext", $name);
        move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name);
    }
    return UPLOAD_URL . '/' . $name;
}

/* ---------- Generación de páginas ---------- */
function render_article(array $p): string {
    $tpl = (string) file_get_contents(PANEL_DIR . '/plantilla-articulo.html');
    $url = SITE_URL . '/' . file_for($p['slug']);
    $img = SITE_URL . '/' . $p['cover'];
    $ld = json_encode([
        '@context' => 'https://schema.org', '@type' => 'BlogPosting',
        'headline' => $p['title'], 'description' => $p['summary'], 'image' => $img,
        'datePublished' => $p['date'], 'dateModified' => date('Y-m-d'), 'inLanguage' => 'es',
        'author' => ['@type' => 'Organization', 'name' => 'Insight LATAM'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Insight LATAM', 'logo' => ['@type' => 'ImageObject', 'url' => SITE_URL . '/assets/images/logo/logo2.png']],
        'mainEntityOfPage' => $url,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT);
    $rep = [
        '{{TITLE}}' => h($p['title']),
        '{{SUMMARY}}' => h($p['summary']),
        '{{URL}}' => h($url),
        '{{URL_ENC}}' => rawurlencode($url),
        '{{IMAGE}}' => h($p['cover']),
        '{{IMAGE_ABS}}' => h($img),
        '{{CATEGORY}}' => h($p['category']),
        '{{DATE_LONG}}' => h(fecha_larga($p['date'])),
        '{{MINUTES}}' => (string) $p['minutes'],
        '{{JSONLD}}' => $ld,
        '{{CONTENT}}' => str_replace('<blockquote>', '<blockquote class="article-callout">', $p['content']),
    ];
    return strtr($tpl, $rep);
}

function render_card(array $p): string {
    $f = h(file_for($p['slug']));
    return '                    <article class="blog-card">
                        <a class="blog-card__img" href="' . $f . '">
                            <img src="' . h($p['cover']) . '" alt="' . h($p['title']) . '" loading="lazy">
                        </a>
                        <div class="blog-card__body">
                            <span class="blog-card__tag">' . h($p['category']) . '</span>
                            <h2><a href="' . $f . '">' . h($p['title']) . '</a></h2>
                            <p>' . h($p['summary']) . '</p>
                            <div class="blog-card__footer">
                                <span><i class="fa fa-calendar"></i> ' . h(fecha_corta($p['date'])) . ' · ' . (int) $p['minutes'] . ' min</span>
                                <a class="blog-card__more" href="' . $f . '">Leer artículo →</a>
                            </div>
                        </div>
                    </article>';
}

/** Vuelve a generar la lista de blog.html y el sitemap.xml. */
function rebuild_site(): void {
    $list = posts();
    $blog = SITE_DIR . '/blog.html';
    $html = (string) file_get_contents($blog);
    $start = '<!-- POSTS:START -->'; $end = '<!-- POSTS:END -->';
    $a = strpos($html, $start); $b = strpos($html, $end);
    if ($a === false || $b === false) throw new RuntimeException('blog.html no tiene las marcas POSTS:START / POSTS:END.');
    $cards = $list ? implode("\n\n", array_map('render_card', $list)) : '                    <p class="blog-empty">Pronto publicaremos nuestros primeros artículos.</p>';
    $html = substr($html, 0, $a + strlen($start)) . "\n" . $cards . "\n                    " . substr($html, $b);
    atomic_write($blog, $html);

    $base = [['', '1.0'], ['about.html', '0.8'], ['service.html', '0.9'], ['blog.html', '0.8'], ['contact.html', '0.7'],
             ['comunicacion.html', '0.6'], ['distribucion.html', '0.6'], ['experiencia.html', '0.6'], ['innovacion.html', '0.6'],
             ['maryesta.html', '0.6'], ['social.html', '0.6'], ['terminos-y-condiciones.html', '0.3']];
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $today = date('Y-m-d');
    foreach ($base as [$path, $prio]) {
        $x .= "  <url>\n    <loc>" . SITE_URL . "/$path</loc>\n    <lastmod>$today</lastmod>\n    <priority>$prio</priority>\n  </url>\n";
    }
    foreach ($list as $p) {
        $x .= "  <url>\n    <loc>" . SITE_URL . '/' . file_for($p['slug']) . "</loc>\n    <lastmod>" . h($p['updated'] ?? $p['date']) . "</lastmod>\n    <priority>0.7</priority>\n  </url>\n";
    }
    $x .= "</urlset>\n";
    atomic_write(SITE_DIR . '/sitemap.xml', $x);
}
