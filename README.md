# Insight LATAM — Sitio corporativo y panel de blog

Sitio web corporativo de **Insight LATAM**, firma de investigación de mercados, inteligencia y estrategia en América Latina. Incluye un **panel de administración de blog** propio, desarrollado en PHP, que publica artículos como páginas estáticas optimizadas para SEO.

## Funcionalidades

### Sitio público
- Páginas de inicio, nosotros, soluciones, contacto, términos y blog.
- Páginas de soluciones por línea de negocio (comunicación, distribución, experiencia del consumidor, innovación, marca y estrategia, social media).
- Videos de fondo, carruseles y contadores animados.
- `sitemap.xml` y `robots.txt` para indexación.

### Panel de blog (`/panel-blog`)
- Inicio de sesión con bloqueo por intentos fallidos y protección CSRF.
- Editor de artículos con portada, fecha, extracto y contenido enriquecido.
- Sanitización del HTML del artículo con `DOMDocument` (lista blanca de etiquetas).
- Generación automática de la página de cada artículo, del listado del blog y del `sitemap.xml`.
- Sin base de datos: la información se guarda en archivos con escritura atómica.

## Tecnologías

| Capa | Tecnología |
|------|-----------|
| Frontend | HTML5, CSS3, JavaScript, jQuery, Bootstrap, Owl Carousel |
| Panel de blog | PHP 8 |
| Persistencia | Archivos PHP/JSON (sin base de datos) |

## Estructura

```text
*.html                  Páginas del sitio
assets/css, assets/js   Estilos y scripts
assets/images, Videos   Recursos multimedia
panel-blog/             Panel de administración del blog
  index.php             Interfaz del panel
  lib.php               Autenticación, sanitización y generación de páginas
  plantilla-articulo.html
blog-plantilla.html     Plantilla base para artículos
```

## Ejecución local

```bash
php -S localhost:8000
```

Luego abre `http://localhost:8000`. El panel de blog está en `http://localhost:8000/panel-blog/`.

---

## Autor

**Ariel Geovanny Andino Chinchilla** — Desarrollador Full Stack

- GitHub: [@andino1234](https://github.com/andino1234)
- Repositorio: [github.com/andino1234/insightlatam](https://github.com/andino1234/insightlatam)

Proyecto diseñado y desarrollado por Ariel Geovanny Andino Chinchilla para Insight LATAM. Se publica con fines de portafolio; las credenciales, datos de clientes y archivos de usuarios se excluyeron del repositorio.
