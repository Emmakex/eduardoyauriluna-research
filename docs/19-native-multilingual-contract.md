# Native English / Spanish Research contract

## Language authority

The Research Theme owns its bilingual baseline and does not require WPML or Polylang.

- English (`en`) is the default and canonical root language.
- Spanish (`es`) is a first-class locale below `/es/`.
- The Theme language service is the single authority for route locale, page labels, navigation, canonical URLs, hreflang, Open Graph locale and Schema `inLanguage`.
- Optional third-party multilingual adapters may be added later, but they must consume this contract rather than replace it.

## Controlled page routes

English keeps the root URLs already established by the Research preset. Spanish aliases reuse the same Theme-owned page models while exposing localized public paths.

| Surface | English | Spanish |
| --- | --- | --- |
| Home | `/` | `/es/` |
| Profile | `/about/` | `/es/perfil/` |
| Research | `/research/` | `/es/investigacion/` |
| Publications | `/publications/` | `/es/publicaciones/` |
| Projects | `/projects/` | `/es/proyectos/` |
| Software | `/software/` | `/es/software/` |
| Datasets | `/datasets/` | `/es/datos/` |
| CV | `/cv/` | `/es/cv/` |
| Insights | `/insights/` | `/es/notas/` |
| Contact | `/contact/` | `/es/contacto/` |
| Privacy | `/privacy-policy/` | `/es/privacidad/` |
| Legal notice | `/legal-notice/` | `/es/aviso-legal/` |

The English WordPress Page records remain structural routing records. Spanish aliases resolve to those Theme-owned surfaces without duplicating Gutenberg layouts.

## Research records and Insights

Routable research content uses separate records per language when a translation exists.

- `_research_language`: `en` or `es`. Missing values are treated as English for backwards compatibility.
- `_research_translation_en`: post ID of the English counterpart.
- `_research_translation_es`: post ID of the Spanish counterpart.

Spanish record routes are localized, for example:

- `/es/publicaciones/<slug>/`
- `/es/proyectos/<slug>/`
- `/es/software/<slug>/`
- `/es/datos/<slug>/`
- `/es/notas/<slug>/`

A Spanish index only queries Spanish records. An English index only queries English records or legacy records without a language meta value. The Theme must never silently present an English record as Spanish.

## Structured evidence translations

Evidence records are data rather than independent routable documents, so they remain in the verified evidence store and may carry localized fields.

Supported Spanish forms are:

- a `translations.es` object; or
- `title_es`, `label_es`, `summary_es`, `value_es` fields.

The evidence publication rule does not change: only records with `status=verified` may be rendered publicly in either language.

## SEO / GEO rules

Controlled page pairs emit:

- a self-referencing canonical for the current locale;
- reciprocal `hreflang=en` and `hreflang=es`;
- `hreflang=x-default` pointing to English;
- locale-consistent HTML language, Open Graph locale and Schema `inLanguage`.

Translated research records emit cross-language hreflang only when a valid published counterpart actually exists. No alternate URL is fabricated.

The WordPress canonical redirect must not collapse Spanish aliases back to their English structural Page URL.

## Machine-readable discovery

The language context is preserved for machine-facing routes:

- `/llms.txt`
- `/research.json`
- `/es/llms.txt`
- `/es/research.json`

The Spanish JSON index returns Spanish-owned research records; the English index returns English-owned records.

## Future Research Manager contract

The future Manager is the writer/controller for this Theme contract. It may create translations, relate EN/ES record pairs, hydrate language-specific Theme model options and update localized evidence through Preview → Apply → Verify → Rollback.

The Manager must not turn Gutenberg into the layout authority and must not bypass the verified-evidence publication rule.
