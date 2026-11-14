# Site Architect #475.17 — exportación autónoma verificable

El producto es **Site Architect 0.11.1 real**, no otro plugin reducido.

Desde el commit exacto del repositorio de Eduardo:

    python3 scripts/test-site-architect-product-export.py
    python3 scripts/export-site-architect-product.py --source-commit "$(git rev-parse HEAD)"

Artefactos generados en dist/site-architect-standalone:
- site-architect-0.11.1-standalone-source-candidate.zip
- checksum SHA-256 del ZIP
- manifest de procedencia con hashes SHA-256 de todos los archivos

Al extraerlo, site-architect/ contiene TODO el plugin real, un CI propio,
script constructor, prueba, README y SOURCE_COMMIT. Su ZIP WordPress se
reconstruye fuera del repositorio original y es idéntico byte a byte.

La extensión Research queda presente y ligada al Theme Research; WordPress
genérico conserva solo las operaciones de lectura ya probadas. No se incluye
Theme, Blueprint de la instancia, contraseñas, datos ni configuración de Eduardo.

## Aceptación

GitHub Actions verifica el build autónomo y lo instala desde el ZIP
reconstruido en WordPress nuevo. En Theme Research comprueba el Blueprint:
Bootstrap 8 Lines con evidencia, identidad y 24 hidrataciones bilingües,
Apply/Verify, Preview idempotente y Rollback. En WordPress genérico bloquea
el Blueprint Research antes de cualquier escritura.

## Límites pendientes

No existe aún un repositorio verificable Emmakex/site-architect, y la conexión
disponible no permite crearlo. Esta exportación queda lista para importarse
en un repositorio privado nuevo una vez creado. No se presume licencia de
publicación ni autorización para publicar código privado.

No se ha certificado todo el contenido/renders de producción ni el cutover
reversible, ni la escritura de un segundo Theme arbitrario. La web de Eduardo
conserva Site Architect 0.11.1 intacto. El SOURCE_COMMIT es la revisión de
origen, no un supuesto commit del futuro repositorio de producto.
