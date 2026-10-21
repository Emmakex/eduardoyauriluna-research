<?php
/** Theme-owned semantic slot models. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_canonical_identity_name(): string {
    $stored = get_option('eduardo_research_identity', array());
    if (is_array($stored) && isset($stored['name']) && is_scalar($stored['name'])) {
        $name = trim((string) $stored['name']);
        if ('' !== $name) { return $name; }
    }
    $fallback = trim((string) get_bloginfo('name'));
    return '' !== $fallback ? $fallback : 'Eduardo Jose Yauri Luna';
}

function eduardo_research_canonical_identity_headline(?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $stored = get_option('eduardo_research_identity', array());
    $key = 'es' === $language ? 'headline_es' : 'headline';
    if (is_array($stored) && isset($stored[$key]) && is_scalar($stored[$key])) {
        $headline = trim((string) $stored[$key]);
        if ('' !== $headline) { return $headline; }
    }
    return 'es' === $language
        ? 'Inteligencia Artificial, Transformación Digital y Sistemas Autónomos para Procesos Empresariales'
        : 'Artificial Intelligence, Digital Transformation and Autonomous Systems for Business Processes';
}

function eduardo_research_default_model(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $name = eduardo_research_canonical_identity_name();
    $headline = eduardo_research_canonical_identity_headline($language);
    if ('es' === $language) {
        return array(
            'hero-eyebrow'=>'Perfil de investigación',
            'researcher-name'=>$name,
            'researcher-headline'=>$headline,
            'hero-lead'=>'Investigación aplicada sobre cómo diseñar, integrar y evaluar sistemas de IA autónomos y semiautónomos en entornos organizativos reales manteniendo fiabilidad, trazabilidad y gobernanza.',
            'hero-primary-target'=>'research',
            'hero-secondary-target'=>'cv',
            'research-lines-heading'=>'Agenda de investigación',
            'research-lines-intro'=>'La agenda de investigación se articula en torno a agentes autónomos de IA, colaboración humano–IA, transformación digital en pymes y descubrimiento de información mediado por IA.',
            'selected-outputs-heading'=>'Resultados de investigación',
            'selected-outputs-intro'=>'Publicaciones, proyectos, software de investigación y conjuntos de datos derivados de la agenda de investigación.',
            'academic-identifiers-heading'=>'Perfiles académicos',
            'latest-insights-heading'=>'Notas de investigación',
            'final-cta-heading'=>'Investigación y colaboración',
            'final-cta-body'=>'Explora las líneas de investigación, las preguntas que las orientan y los métodos planteados para su desarrollo.',
            'final-cta-button'=>'Explorar investigación',
            'final-cta-target'=>'contact',
        );
    }
    return array(
        'hero-eyebrow'=>'Research profile',
        'researcher-name'=>$name,
        'researcher-headline'=>$headline,
        'hero-lead'=>'Applied research on how autonomous and semi-autonomous AI systems can be designed, integrated and evaluated in real organisational environments while remaining reliable, traceable and governable.',
        'hero-primary-target'=>'research',
        'hero-secondary-target'=>'cv',
        'research-lines-heading'=>'Research agenda',
        'research-lines-intro'=>'The research agenda focuses on autonomous AI agents, human–AI collaboration, digital transformation in SMEs and AI-mediated information discovery.',
        'selected-outputs-heading'=>'Research outputs',
        'selected-outputs-intro'=>'Publications, projects, research software and datasets developed from the research agenda.',
        'academic-identifiers-heading'=>'Academic profiles',
        'latest-insights-heading'=>'Research notes',
        'final-cta-heading'=>'Research and collaboration',
        'final-cta-body'=>'Explore the research lines, the questions that guide them and the methods proposed for their development.',
        'final-cta-button'=>'Explore research',
        'final-cta-target'=>'contact',
    );
}

function eduardo_research_surface_models(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    if ('es' === $language) {
        return array(
            'about'=>array('eyebrow'=>'Perfil de investigación','lead'=>'Eduardo Jose Yauri Luna desarrolla una agenda de investigación aplicada sobre sistemas empresariales habilitados por IA, apoyada en más de diez años de experiencia profesional en estrategia digital, tecnologías web, automatización y sistemas empresariales basados en software.','sections'=>array('Perfil de investigación','Trayectoria profesional','Perfiles académicos')),
            'research'=>array('eyebrow'=>'Agenda de investigación','lead'=>'La línea principal estudia cómo los agentes de IA autónomos y semiautónomos pueden mejorar procesos empresariales manteniendo fiabilidad, observabilidad, capacidad de recuperación y gobernanza.','sections'=>array('Líneas de investigación','Preguntas de investigación','Métodos')),
            'publications'=>array('eyebrow'=>'Publicaciones','lead'=>'Publicaciones y otros resultados académicos de Eduardo Jose Yauri Luna.'),
            'projects'=>array('eyebrow'=>'Proyectos de investigación','lead'=>'Proyectos vinculados a la agenda de investigación de Eduardo Jose Yauri Luna.'),
            'software'=>array('eyebrow'=>'Software de investigación','lead'=>'Software y artefactos técnicos desarrollados en relación con la investigación.'),
            'datasets'=>array('eyebrow'=>'Datos de investigación','lead'=>'Conjuntos de datos y recursos de investigación asociados a trabajos y proyectos publicados.'),
            'cv'=>array('eyebrow'=>'Currículum vitae','lead'=>'Trayectoria académica y profesional de Eduardo Jose Yauri Luna, con especial atención a la experiencia relevante para su agenda de investigación en inteligencia artificial, transformación digital y sistemas autónomos.','sections'=>array('Experiencia','Formación','Afiliaciones','Reconocimientos')),
            'insights'=>array('eyebrow'=>'Notas de investigación','lead'=>'Notas de trabajo, explicaciones y observaciones metodológicas relacionadas con la agenda de investigación.'),
            'contact'=>array('eyebrow'=>'Contacto de investigación','lead'=>'Contacto para conversaciones académicas, colaboración y cuestiones relacionadas con la agenda de investigación.','sections'=>array('Contacto','Perfiles académicos')),
            'privacy-policy'=>array('eyebrow'=>'Privacidad','lead'=>'Información de privacidad de eduardoyauriluna.com.','sections'=>array('Tratamiento de datos','Contacto')),
            'legal-notice'=>array('eyebrow'=>'Aviso legal','lead'=>'Información legal y de titularidad de eduardoyauriluna.com.','sections'=>array('Titularidad del sitio','Condiciones de uso')),
        );
    }
    return array(
        'about'=>array('eyebrow'=>'Research profile','lead'=>'Eduardo Jose Yauri Luna develops an applied research agenda around AI-enabled business systems, drawing on more than ten years of professional experience in digital strategy, web technologies, automation and software-based business systems.','sections'=>array('Research profile','Professional background','Academic profiles')),
        'research'=>array('eyebrow'=>'Research agenda','lead'=>'The primary direction examines how autonomous and semi-autonomous AI agents can improve business processes while remaining reliable, observable, recoverable and governable.','sections'=>array('Research lines','Research questions','Methods')),
        'publications'=>array('eyebrow'=>'Publications','lead'=>'Publications and other scholarly outputs by Eduardo Jose Yauri Luna.'),
        'projects'=>array('eyebrow'=>'Research projects','lead'=>'Projects connected to the research agenda of Eduardo Jose Yauri Luna.'),
        'software'=>array('eyebrow'=>'Research software','lead'=>'Software and technical artefacts developed in connection with the research agenda.'),
        'datasets'=>array('eyebrow'=>'Research datasets','lead'=>'Datasets and research resources associated with published work and projects.'),
        'cv'=>array('eyebrow'=>'Curriculum vitae','lead'=>'Academic and professional background of Eduardo Jose Yauri Luna, with particular focus on experience relevant to his research agenda in artificial intelligence, digital transformation and autonomous systems.','sections'=>array('Experience','Education','Affiliations','Awards')),
        'insights'=>array('eyebrow'=>'Research notes','lead'=>'Working notes, explainers and methodological observations related to the research agenda.'),
        'contact'=>array('eyebrow'=>'Research contact','lead'=>'Contact for academic conversations, collaboration and enquiries related to the research agenda.','sections'=>array('Contact','Academic profiles')),
        'privacy-policy'=>array('eyebrow'=>'Privacy','lead'=>'Privacy information for eduardoyauriluna.com.','sections'=>array('Data handling','Contact')),
        'legal-notice'=>array('eyebrow'=>'Legal notice','lead'=>'Legal and ownership information for eduardoyauriluna.com.','sections'=>array('Site ownership','Terms of use')),
    );
}

function eduardo_research_model(): array {
    $language = eduardo_research_current_language();
    $defaults = eduardo_research_default_model($language);
    $option = 'es' === $language ? 'eduardo_research_model_es' : 'eduardo_research_model';
    $stored = get_option($option, array());
    if (! is_array($stored)) { return $defaults; }
    $model = array_replace($defaults, array_intersect_key($stored, $defaults));
    // Researcher identity is canonical Manager evidence, never a free editorial slot.
    $model['researcher-name'] = eduardo_research_canonical_identity_name();
    $model['researcher-headline'] = eduardo_research_canonical_identity_headline($language);
    return $model;
}

function eduardo_research_slot(string $key): string {
    $model = eduardo_research_model();
    return isset($model[$key]) && is_scalar($model[$key]) ? (string) $model[$key] : '';
}

function eduardo_research_surface_model(string $key): array {
    $language = eduardo_research_current_language();
    $models = eduardo_research_surface_models($language);
    $defaults = $models[$key] ?? array('eyebrow'=>('es' === $language ? 'Investigación' : 'Research'),'lead'=>('es' === $language ? 'Perfil de investigación de Eduardo Jose Yauri Luna.' : 'Research profile of Eduardo Jose Yauri Luna.'));
    $option = 'eduardo_research_surface_' . sanitize_key($key) . ('es' === $language ? '_es' : '');
    $stored = get_option($option, array());
    return is_array($stored) ? array_replace($defaults, array_intersect_key($stored, $defaults)) : $defaults;
}
