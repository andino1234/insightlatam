<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require __DIR__ . '/lib.php';
set_exception_handler(function (Throwable $e) {
    error_log('[panel-blog] ' . $e->getMessage());
    http_response_code(500);
    $perm = stripos($e->getMessage(), 'escribir') !== false || stripos($e->getMessage(), 'permis') !== false;
    echo '<!doctype html><meta charset="utf-8"><meta name="author" content="Ariel Geovanny Andino Chinchilla"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Panel del blog</title>'
       . '<body style="font-family:system-ui,sans-serif;background:#07111f;color:#e6eef8;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
       . '<div style="max-width:520px;background:#0d1f36;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:28px">'
       . '<h1 style="margin:0 0 10px;font-size:22px">No se pudo completar la acción</h1>'
       . ($perm ? '<p style="line-height:1.6;color:#c8d6e8">El servidor no deja que el panel guarde archivos. En aaPanel, en <b>Files</b>, abre los permisos de la carpeta del sitio, pon dueño <b>www</b>, permiso <b>755</b> y aplica a las subcarpetas.</p>'
               : '<p style="line-height:1.6;color:#c8d6e8">Ocurrió un error inesperado. Inténtalo de nuevo en unos minutos.</p>')
       . '<p><a style="color:#8fd6ff" href="index.php">Volver al panel</a></p></div></body>';
});

$action = $_POST['action'] ?? '';
$view = $_GET['a'] ?? 'list';
$msg = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$err = '';

function flash_redirect(string $m, string $to = 'index.php'): void { $_SESSION['flash'] = $m; header('Location: ' . $to); exit; }

/* ---------- Acciones ---------- */
if ($action === 'login') {
    csrf_check();
    if ($mins = login_locked()) {
        $err = "Demasiados intentos fallidos. Espera $mins minutos e inténtalo de nuevo.";
    } elseif (login_attempt((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''))) {
        $next = in_array($_POST['next'] ?? '', ['new'], true) ? 'index.php?a=new' : 'index.php';
        flash_redirect('Bienvenido.', $next);
    } else {
        $err = 'Usuario o contraseña incorrectos.';
    }
}

if ($action === 'logout') { csrf_check(); $_SESSION = []; session_destroy(); header('Location: index.php'); exit; }

if (is_logged_in() && $action === 'save') {
    csrf_check();
    try {
        $all = data_read('posts', []);
        $orig = (string) ($_POST['orig'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? '')) ?: 'Investigación de mercados';
        $date = (string) ($_POST['date'] ?? date('Y-m-d'));
        $content = sanitize_html((string) ($_POST['content'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) throw new RuntimeException('Escribe un título (máximo 160 caracteres).');
        if ($summary === '' || mb_strlen($summary) > 300) throw new RuntimeException('Escribe un resumen (máximo 300 caracteres).');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        if (mb_strlen(trim(strip_tags($content))) < 50) throw new RuntimeException('El contenido del artículo está vacío o es muy corto.');

        $existing = null; $idx = null;
        foreach ($all as $i => $p) if ($p['slug'] === $orig) { $existing = $p; $idx = $i; }

        if ($existing) {
            $slug = $existing['slug']; // la dirección no cambia al editar
        } else {
            $slug = slugify((string) ($_POST['slug'] ?? '') ?: $title);
            $base = $slug; $n = 2;
            while (find_post($slug) || is_file(SITE_DIR . '/' . file_for($slug))) $slug = $base . '-' . $n++;
        }
        $cover = save_cover($_FILES['cover'] ?? []) ?? ($existing['cover'] ?? DEFAULT_COVER);
        $post = [
            'slug' => $slug, 'title' => $title, 'summary' => $summary, 'category' => $category,
            'date' => $date, 'cover' => $cover, 'content' => $content, 'minutes' => reading_minutes($content),
            'created' => $existing['created'] ?? date('c'), 'updated' => date('Y-m-d'),
        ];
        atomic_write(SITE_DIR . '/' . file_for($slug), render_article($post));
        if ($idx !== null) $all[$idx] = $post; else $all[] = $post;
        save_posts($all);
        rebuild_site();
        flash_redirect(($existing ? 'Artículo actualizado: ' : 'Artículo publicado: ') . $title . '|' . file_for($slug));
    } catch (Throwable $e) {
        $err = $e->getMessage();
        $view = $orig !== '' ? 'edit' : 'new';
        $draft = $_POST;
    }
}

if (is_logged_in() && $action === 'delete') {
    csrf_check();
    $slug = (string) ($_POST['slug'] ?? '');
    $all = data_read('posts', []);
    $keep = [];
    foreach ($all as $p) {
        if ($p['slug'] === $slug) {
            $f = SITE_DIR . '/' . file_for($p['slug']);
            if (is_file($f)) unlink($f);
            if (strpos($p['cover'], UPLOAD_URL . '/') === 0 && is_file(SITE_DIR . '/' . $p['cover'])) unlink(SITE_DIR . '/' . $p['cover']);
        } else $keep[] = $p;
    }
    save_posts($keep);
    rebuild_site();
    flash_redirect('Artículo eliminado.');
}

if (is_logged_in() && $action === 'password') {
    csrf_check();
    $cfg = auth_config();
    $cur = (string) ($_POST['current'] ?? ''); $new = (string) ($_POST['new'] ?? ''); $rep = (string) ($_POST['repeat'] ?? '');
    if (!password_verify($cur, $cfg['hash'] ?? '')) { $err = 'La contraseña actual no es correcta.'; $view = 'password'; }
    elseif (strlen($new) < 10) { $err = 'La nueva contraseña debe tener al menos 10 caracteres.'; $view = 'password'; }
    elseif ($new !== $rep) { $err = 'Las dos contraseñas nuevas no coinciden.'; $view = 'password'; }
    else { $cfg['hash'] = password_hash($new, PASSWORD_DEFAULT); data_write('auth', $cfg); flash_redirect('Contraseña cambiada.'); }
}

$logged = is_logged_in();
if (!$logged) $view = 'login';
$editing = null;
if ($view === 'edit') { $editing = find_post((string) ($_GET['slug'] ?? $_POST['orig'] ?? '')); if (!$editing && empty($draft)) $view = 'list'; }
$form = $draft ?? ($editing ?? ['title' => '', 'summary' => '', 'category' => 'Investigación de mercados', 'date' => date('Y-m-d'), 'content' => '', 'slug' => '']);
$token = csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="author" content="Ariel Geovanny Andino Chinchilla">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel del blog | Insight LATAM</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="shortcut icon" href="../assets/images/logo/logo2.png">
<style>
:root{--bg:#07111f;--panel:#0d1f36;--panel2:#12304f;--line:rgba(255,255,255,.1);--fg:#e6eef8;--muted:#9fb3cc;--blue:#1e90ff;--green:#56CC57;--red:#ff6b6b}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,sans-serif;background:radial-gradient(circle at top right,rgba(30,144,255,.16),transparent 30%),linear-gradient(180deg,#06101d,#0b1f38 60%,#0b2b4d);color:var(--fg);min-height:100vh}
a{color:#8fd6ff}
.wrap{max-width:1100px;margin:0 auto;padding:28px max(16px,env(safe-area-inset-left))}
.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:28px}
.top img{height:42px}
.top nav{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
h1{font-size:30px;margin:0 0 6px;letter-spacing:-.02em}
h2{font-size:20px;margin:0 0 14px}
p.sub{color:var(--muted);margin:0 0 24px}
.btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:999px;padding:11px 20px;font:600 14px Inter,sans-serif;cursor:pointer;text-decoration:none;color:#fff;background:linear-gradient(90deg,var(--blue),var(--green))}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--fg)}
.btn.danger{background:transparent;border:1px solid rgba(255,107,107,.5);color:var(--red)}
.btn.small{padding:7px 14px;font-size:13px}
.card{background:linear-gradient(180deg,rgba(13,31,54,.95),rgba(18,48,79,.9));border:1px solid var(--line);border-radius:22px;padding:28px;box-shadow:0 24px 70px rgba(0,0,0,.25)}
.flash{padding:14px 18px;border-radius:14px;margin-bottom:20px;background:rgba(86,204,87,.12);border:1px solid rgba(86,204,87,.35);color:#c9f5ca}
.error{background:rgba(255,107,107,.12);border-color:rgba(255,107,107,.4);color:#ffd0d0}
label{display:block;font-weight:600;font-size:14px;margin:0 0 6px}
.hint{color:var(--muted);font-size:13px;font-weight:400}
input[type=text],input[type=password],input[type=date],textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:#081627;color:var(--fg);font:15px Inter,sans-serif}
input:focus,textarea:focus,.editor:focus{outline:2px solid var(--blue);outline-offset:1px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.field{margin-bottom:18px}
.posts{width:100%;border-collapse:collapse}
.posts td,.posts th{padding:14px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle;font-size:14px}
.posts th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
.posts img{width:72px;height:48px;object-fit:cover;border-radius:8px;display:block}
.posts .actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.toolbar{display:flex;flex-wrap:wrap;gap:6px;padding:8px;border:1px solid var(--line);border-bottom:0;border-radius:12px 12px 0 0;background:#0a1a2e}
.toolbar button{border:1px solid var(--line);background:#102741;color:var(--fg);border-radius:8px;padding:7px 11px;font:600 13px Inter,sans-serif;cursor:pointer}
.toolbar button:hover{background:#17375a}
.editor{min-height:420px;max-height:70vh;overflow:auto;padding:22px 26px;border:1px solid var(--line);border-radius:0 0 12px 12px;background:#fbfcfe;color:#33475e;font-size:16px;line-height:1.8}
.editor h2{color:#0a1e35;font-size:26px;margin:26px 0 10px}
.editor h3{color:#0b3f72;font-size:20px;margin:20px 0 8px}
.editor blockquote{margin:18px 0;padding:16px 20px;border-left:5px solid var(--blue);background:#eef6ff;border-radius:12px}
.editor a{color:#1678dc}
.editor:empty:before{content:attr(data-placeholder);color:#99a8b8}
.login{max-width:420px;margin:8vh auto 0}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.counter{float:right;font-weight:400;color:var(--muted);font-size:12px}
@media (max-width:720px){.grid{grid-template-columns:1fr}.posts .hide-sm{display:none}.card{padding:20px}}
</style>
</head>
<body>
<div class="wrap">
<?php if ($view === 'login'): ?>
  <div class="login">
    <div class="top" style="justify-content:center"><img src="../assets/images/logo/logo30.png" alt="Insight LATAM"></div>
    <div class="card">
      <h1>Panel del blog</h1>
      <p class="sub"><?= (($_GET['a'] ?? $_POST['next'] ?? '') === 'new') ? 'Inicia sesión para crear un nuevo artículo.' : 'Inicia sesión para crear y editar artículos.' ?></p>
      <?php if ($err): ?><div class="flash error"><?= h($err) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="next" value="<?= h(($_GET['a'] ?? $_POST['next'] ?? '') === 'new' ? 'new' : '') ?>">
        <div class="field"><label for="user">Usuario</label><input type="text" id="user" name="user" required autofocus></div>
        <div class="field"><label for="pass">Contraseña</label><input type="password" id="pass" name="pass" required></div>
        <button class="btn" type="submit" style="width:100%;justify-content:center">Entrar</button>
      </form>
    </div>
    <p class="sub" style="text-align:center;margin-top:18px"><a href="../index.html">← Volver al sitio</a></p>
  </div>
<?php else: ?>
  <div class="top">
    <a href="index.php"><img src="../assets/images/logo/logo30.png" alt="Insight LATAM"></a>
    <nav>
      <a class="btn ghost small" href="../blog.html" target="_blank">Ver blog</a>
      <a class="btn ghost small" href="index.php?a=password">Cambiar contraseña</a>
      <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="logout"><button class="btn ghost small" type="submit">Cerrar sesión</button></form>
    </nav>
  </div>

  <?php if ($msg):
      [$text, $file] = array_pad(explode('|', $msg, 2), 2, ''); ?>
    <div class="flash"><?= h($text) ?><?php if ($file): ?> — <a href="../<?= h($file) ?>" target="_blank">ver artículo</a><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($err): ?><div class="flash error"><?= h($err) ?></div><?php endif; ?>

  <?php if ($view === 'list'): $list = posts(); ?>
    <div class="row" style="justify-content:space-between;margin-bottom:20px">
      <div><h1>Artículos del blog</h1><p class="sub" style="margin:0"><?= count($list) ?> publicados</p></div>
      <a class="btn" href="index.php?a=new">+ Nuevo artículo</a>
    </div>
    <div class="card" style="padding:8px 18px;overflow-x:auto">
      <?php if (!$list): ?><p class="sub" style="padding:20px 0">Todavía no hay artículos. Crea el primero con “Nuevo artículo”.</p><?php else: ?>
      <table class="posts">
        <tr><th class="hide-sm"></th><th>Título</th><th class="hide-sm">Fecha</th><th></th></tr>
        <?php foreach ($list as $p): ?>
        <tr>
          <td class="hide-sm"><img src="../<?= h($p['cover']) ?>" alt=""></td>
          <td><strong><?= h($p['title']) ?></strong><br><span class="hint"><?= h($p['category']) ?> · <?= (int) $p['minutes'] ?> min</span></td>
          <td class="hide-sm"><?= h(fecha_corta($p['date'])) ?></td>
          <td><div class="actions">
            <a class="btn ghost small" href="../<?= h(file_for($p['slug'])) ?>" target="_blank">Ver</a>
            <a class="btn ghost small" href="index.php?a=edit&amp;slug=<?= h(rawurlencode($p['slug'])) ?>">Editar</a>
            <form method="post" style="margin:0" onsubmit="return confirm('¿Eliminar este artículo del sitio? No se puede deshacer.');">
              <input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="slug" value="<?= h($p['slug']) ?>">
              <button class="btn danger small" type="submit">Eliminar</button>
            </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>

  <?php elseif ($view === 'new' || $view === 'edit'): ?>
    <h1><?= $view === 'edit' ? 'Editar artículo' : 'Nuevo artículo' ?></h1>
    <p class="sub">Pega aquí el texto que exportaste de AnswerThePublic y ajusta el formato con la barra de herramientas.</p>
    <form method="post" enctype="multipart/form-data" class="card" id="postForm">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="orig" value="<?= h($editing['slug'] ?? ($_POST['orig'] ?? '')) ?>">
      <input type="hidden" name="content" id="content">
      <div class="field"><label for="title">Título <span class="counter" data-for="title" data-max="70"></span></label>
        <input type="text" id="title" name="title" maxlength="160" required value="<?= h($form['title']) ?>" placeholder="Ej.: ¿Cuánto cuesta un estudio de mercado en Guatemala?"></div>
      <div class="field"><label for="summary">Resumen <span class="hint">(aparece en la tarjeta del blog y en Google)</span> <span class="counter" data-for="summary" data-max="160"></span></label>
        <textarea id="summary" name="summary" rows="2" maxlength="300" required placeholder="Una o dos frases que resuman el artículo."><?= h($form['summary']) ?></textarea></div>
      <div class="grid">
        <div class="field"><label for="category">Categoría</label><input type="text" id="category" name="category" value="<?= h($form['category']) ?>"></div>
        <div class="field"><label for="date">Fecha de publicación</label><input type="date" id="date" name="date" value="<?= h($form['date']) ?>"></div>
      </div>
      <div class="field"><label for="cover">Imagen de portada <span class="hint">(JPG, PNG o WEBP, máx. 8 MB<?= $view === 'edit' ? '; déjalo vacío para conservar la actual' : '; si no subes una, se usa una imagen por defecto' ?>)</span></label>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"></div>
      <div class="field">
        <label>Contenido</label>
        <div class="toolbar" role="toolbar" aria-label="Formato">
          <button type="button" data-cmd="formatBlock" data-val="P">Párrafo</button>
          <button type="button" data-cmd="formatBlock" data-val="H2">Subtítulo</button>
          <button type="button" data-cmd="formatBlock" data-val="H3">Subtítulo pequeño</button>
          <button type="button" data-cmd="bold"><b>N</b></button>
          <button type="button" data-cmd="italic"><i>K</i></button>
          <button type="button" data-cmd="insertUnorderedList">• Lista</button>
          <button type="button" data-cmd="insertOrderedList">1. Lista</button>
          <button type="button" data-cmd="formatBlock" data-val="BLOCKQUOTE">Destacado</button>
          <button type="button" data-cmd="link">Enlace</button>
          <button type="button" data-cmd="removeFormat">Quitar formato</button>
        </div>
        <div class="editor" id="editor" contenteditable="true" data-placeholder="Escribe o pega aquí el artículo…"><?= sanitize_html((string) ($form['content'] ?? '')) ?></div>
      </div>
      <div class="row">
        <button class="btn" type="submit"><?= $view === 'edit' ? 'Guardar cambios' : 'Publicar artículo' ?></button>
        <a class="btn ghost" href="index.php">Cancelar</a>
      </div>
    </form>
    <script>
    (function(){
      var ed=document.getElementById('editor');
      document.querySelectorAll('.toolbar button').forEach(function(b){
        b.addEventListener('mousedown',function(e){e.preventDefault();});
        b.addEventListener('click',function(){
          var c=b.dataset.cmd;
          if(c==='link'){var u=prompt('Dirección del enlace (https://… o página del sitio, ej. contact.html):');if(u)document.execCommand('createLink',false,u);}
          else document.execCommand(c,false,b.dataset.val||null);
          ed.focus();
        });
      });
      document.getElementById('postForm').addEventListener('submit',function(){document.getElementById('content').value=ed.innerHTML;});
      document.querySelectorAll('.counter').forEach(function(c){
        var i=document.getElementById(c.dataset.for),m=+c.dataset.max;
        var u=function(){var n=i.value.length;c.textContent=n+' / '+m+' recomendados';c.style.color=n>m?'#ffb86b':'';};
        i.addEventListener('input',u);u();
      });
    })();
    </script>

  <?php elseif ($view === 'password'): ?>
    <div class="card" style="max-width:480px">
      <h1>Cambiar contraseña</h1>
      <p class="sub">Mínimo 10 caracteres.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($token) ?>"><input type="hidden" name="action" value="password">
        <div class="field"><label for="current">Contraseña actual</label><input type="password" id="current" name="current" required></div>
        <div class="field"><label for="new">Nueva contraseña</label><input type="password" id="new" name="new" required minlength="10"></div>
        <div class="field"><label for="repeat">Repite la nueva contraseña</label><input type="password" id="repeat" name="repeat" required minlength="10"></div>
        <div class="row"><button class="btn" type="submit">Guardar</button><a class="btn ghost" href="index.php">Cancelar</a></div>
      </form>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
