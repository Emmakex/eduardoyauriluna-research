<?php
/** Theme-owned semantic slot models. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_default_model(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $name = get_bloginfo('name') ?: ('es' === $language ? 'Investigador' : 'Researcher');
    if ('es' === $language) {
        return array(
            'hero-eyebrow'=>'Perfil de investigación',
            'researcher-name'=>$name,
            'researcher-headline'=>'Investigación, software y sistemas digitales basados en evidencia',
            'hero-lead'=>'Un perfil de investigación estructurado para el descubrimiento académico, la reproducibilidad y el contexto académico legible por máquinas.',
            'research-lines-heading'=>'Agenda de investigación',
            'research-lines-intro'=>'Las líneas activas de investigación, preguntas y métodos se publican aquí con una procedencia clara.',
            'selected-outputs-heading'=>'Resultados seleccionados',
            'selected-outputs-intro'=>'Publicaciones, proyectos, software y datos se representan como objetos de investigación de primera clase.',
            'academic-identifiers-heading'=>'Identidad académica verificada',
            'latest-insights-heading'=>'Últimas notas de investigación',
            'final-cta-heading'=>'Investigación y colaboración',
            'final-cta-body'=>'Explora la agenda de investigación, los resultados y los artefactos reproducibles.',
            'final-cta-button'=>'Explorar investigación',
        );
    }
    return array(
        'hero-eyebrow'=>'Research profile',
        'researcher-name'=>$name,
        'researcher-headline'=>get_bloginfo('description') ?: 'Research, software and evidence-driven digital systems',
        'hero-lead'=>'A structured research profile designed for scholarly discovery, reproducibility and machine-readable academic context.',
        'research-lines-heading'=>'Research agenda',
        'research-lines-intro'=>'Active research lines, questions and methods are published here with clear provenance.',
        'selected-outputs-heading'=>'Selected outputs',
        'selected-outputs-intro'=>'Publications, projects, software and datasets are represented as first-class research objects.',
        'academic-identifiers-heading'=>'Verified academic identity',
        'latest-insights-heading'=>'Latest research notes',
        'final-cta-heading'=>'Research and collaboration',
        'final-cta-body'=>'Explore the research agenda, outputs and reproducible artefacts.',
        'final-cta-button'=>'Explore research',
    );
}

function eduardo_research_surface_models(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    if ('es' === $language) {
        return array(
            'about'=>array('eyebrow'=>'Perfil','lead'=>'Perfil del investigador, trayectoria y contexto profesional o académico verificado.','sections'=>array('Perfil','Trayectoria','Identidad verificada')),
            'research'=>array('eyebrow'=>'Agenda de investigación','lead'=>'Líneas, preguntas, métodos y trabajo reproducible organizado para personas y máquinas.','sections'=>array('Líneas de investigación','Métodos','Preguntas actuales')),
            'publications'=>array('eyebrow'=>'Resultados de investigación','lead'=>'Resultados académicos y de investigación con estado, procedencia y orientación de citación explícitos.'),
            'projects'=>array('eyebrow'=>'Proyectos','lead'=>'Proyectos de investigación con objetivos, métodos, colaboradores y resultados verificables.'),
            'software'=>array('eyebrow'=>'Software de investigación','lead'=>'Software y artefactos técnicos que apoyan la investigación y la experimentación reproducibles.'),
            'datasets'=>array('eyebrow'=>'Datos','lead'=>'Datos de investigación con procedencia, alcance, condiciones de acceso y contexto de reutilización.'),
            'cv'=>array('eyebrow'=>'Currículum vitae','lead'=>'Currículum académico y de investigación generado a partir de evidencia estructurada y verificada.','sections'=>array('Experiencia','Formación','Resultados de investigación','Proyectos seleccionados')),
            'insights'=>array('eyebrow'=>'Notas','lead'=>'Notas de investigación, explicaciones e ideas de trabajo dentro de una superficie editorial controlada por el Theme.'),
            'contact'=>array('eyebrow'=>'Contacto','lead'=>'Canales de contacto de investigación e identificadores de perfil académico verificados.','sections'=>array('Consultas de investigación','Perfiles académicos')),
            'privacy-policy'=>array('eyebrow'=>'Legal','lead'=>'Información de privacidad de este sitio web de investigación.','sections'=>array('Tratamiento de datos','Contacto')),
            'legal-notice'=>array('eyebrow'=>'Legal','lead'=>'Información legal y de titularidad de este sitio web de investigación.','sections'=>array('Titularidad del sitio','Condiciones de uso')),
        );
    }
    return array(
        'about'=>array('eyebrow'=>'Profile','lead'=>'Researcher profile, background and verified professional or academic context.','sections'=>array('Profile','Background','Verified identity')),
        'research'=>array('eyebrow'=>'Research agenda','lead'=>'Research lines, questions, methods and reproducible work organised for people and machines.','sections'=>array('Research lines','Methods','Current questions')),
        'publications'=>array('eyebrow'=>'Research outputs','lead'=>'Scholarly and research outputs with explicit status, provenance and citation guidance.'),
        'projects'=>array('eyebrow'=>'Projects','lead'=>'Research projects with objectives, methods, collaborators and verifiable outcomes.'),
        'software'=>array('eyebrow'=>'Research software','lead'=>'Software and technical artefacts supporting reproducible research and experimentation.'),
        'datasets'=>array('eyebrow'=>'Datasets','lead'=>'Research datasets with provenance, scope, access conditions and reuse context.'),
        'cv'=>array('eyebrow'=>'Curriculum vitae','lead'=>'Academic and research curriculum generated from verified structured evidence.','sections'=>array('Experience','Education','Research outputs','Selected projects')),
        'insights'=>array('eyebrow'=>'Insights','lead'=>'Research notes, explainers and working ideas inside a Theme-owned editorial surface.'),
        'contact'=>array('eyebrow'=>'Contact','lead'=>'Research contact channels and verified academic profile identifiers.','sections'=>array('Research enquiries','Academic profiles')),
        'privacy-policy'=>array('eyebrow'=>'Legal','lead'=>'Privacy information for this research website.','sections'=>array('Data handling','Contact')),
        'legal-notice'=>array('eyebrow'=>'Legal','lead'=>'Legal and ownership information for this research website.','sections'=>array('Site ownership','Terms of use')),
    );
}

function eduardo_research_model(): array {
    $language = eduardo_research_current_language();
    $defaults = eduardo_research_default_model($language);
    $option = 'es' === $language ? 'eduardo_research_model_es' : 'eduardo_research_model';
    $stored = get_option($option, array());
    if (! is_array($stored)) { return $defaults; }
    return array_replace($defaults, array_intersect_key($stored, $defaults));
}

function eduardo_research_slot(string $key): string {
    $model = eduardo_research_model();
    return isset($model[$key]) && is_scalar($model[$key]) ? (string) $model[$key] : '';
}

function eduardo_research_surface_model(string $key): array {
    $language = eduardo_research_current_language();
    $models = eduardo_research_surface_models($language);
    $defaults = $models[$key] ?? array('eyebrow'=>('es' === $language ? 'Investigación' : 'Research'),'lead'=>('es' === $language ? 'Superficie de perfil de investigación.' : 'Research profile surface.'));
    $option = 'eduardo_research_surface_' . sanitize_key($key) . ('es' === $language ? '_es' : '');
    $stored = get_option($option, array());
    return is_array($stored) ? array_replace($defaults, array_intersect_key($stored, $defaults)) : $defaults;
}
