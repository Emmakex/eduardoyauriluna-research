<?php
/** Research Manager service bootstrap. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager {
    private static ?Eduardo_Research_Manager_Contract $contract = null;
    private static ?Eduardo_Research_Manager_Diagnostics $diagnostics = null;
    private static ?Eduardo_Research_Manager_Executor $executor = null;
    private static ?Eduardo_Research_Manager_Page_Resource $pages = null;
    private static ?Eduardo_Research_Manager_Page_Editor $page_editor = null;
    private static ?Eduardo_Research_Manager_Insight_Resource $insights = null;
    private static ?Eduardo_Research_Manager_Line_Resource $lines = null;
    private static ?Eduardo_Research_Manager_Output_Resource $outputs = null;
    private static ?Eduardo_Research_Manager_Project_Resource $projects = null;
    private static ?Eduardo_Research_Manager_Software_Resource $software = null;
    private static ?Eduardo_Research_Manager_Dataset_Resource $datasets = null;
    private static ?Eduardo_Research_Manager_Rendered_Verifier $rendered = null;
    private static ?Eduardo_Research_Manager_Remediation $remediation = null;
    private static ?Eduardo_Research_Manager_Translation_Pairing $translations = null;
    private static ?Eduardo_Research_Manager_Greenfield $greenfield = null;
    private static ?Eduardo_Research_Manager_Blueprint $blueprint = null;
    private static ?Eduardo_Research_Manager_Blueprint_Store $blueprint_store = null;
    private static ?Eduardo_Research_Manager_Blueprint_Compiler $compiler = null;
    private static ?Eduardo_Research_Manager_Bootstrap $bootstrap = null;
    private static ?Eduardo_Research_Manager_Blueprint_Hydrator $hydrator = null;
    private static ?Eduardo_Research_Manager_Blueprint_Pairing $blueprint_pairing = null;
    private static ?Eduardo_Research_Manager_Blueprint_Relations $blueprint_relations = null;
    private static ?Eduardo_Research_Manager_Greenfield_Pipeline $pipeline = null;

    public static function boot(): void {
        self::$contract = new Eduardo_Research_Manager_Contract();
        self::$diagnostics = new Eduardo_Research_Manager_Diagnostics(self::$contract);
        self::$executor = new Eduardo_Research_Manager_Executor();
        self::$pages = new Eduardo_Research_Manager_Page_Resource(self::$contract);
        self::$page_editor = new Eduardo_Research_Manager_Page_Editor(self::$pages, self::$executor);
        self::$insights = new Eduardo_Research_Manager_Insight_Resource(self::$contract);
        self::$lines = new Eduardo_Research_Manager_Line_Resource(self::$contract);
        self::$outputs = new Eduardo_Research_Manager_Output_Resource(self::$contract);
        self::$projects = new Eduardo_Research_Manager_Project_Resource(self::$contract);
        self::$software = new Eduardo_Research_Manager_Software_Resource(self::$contract);
        self::$datasets = new Eduardo_Research_Manager_Dataset_Resource(self::$contract);
        self::$rendered = new Eduardo_Research_Manager_Rendered_Verifier(self::$contract);
        self::$remediation = new Eduardo_Research_Manager_Remediation(self::$contract, self::$diagnostics, self::$pages);
        self::$translations = new Eduardo_Research_Manager_Translation_Pairing();
        self::$greenfield = new Eduardo_Research_Manager_Greenfield(self::$contract);
        self::$blueprint = new Eduardo_Research_Manager_Blueprint();
        self::$blueprint_store = new Eduardo_Research_Manager_Blueprint_Store(self::$blueprint);
        self::$compiler = new Eduardo_Research_Manager_Blueprint_Compiler(self::$blueprint, self::$greenfield);
        self::$bootstrap = new Eduardo_Research_Manager_Bootstrap(self::$compiler, self::$executor);
        self::$hydrator = new Eduardo_Research_Manager_Blueprint_Hydrator(self::$blueprint, self::$pages, self::$executor);
        self::$blueprint_pairing = new Eduardo_Research_Manager_Blueprint_Pairing(self::$blueprint, self::$lines, self::$translations, self::$executor);
        self::$blueprint_relations = new Eduardo_Research_Manager_Blueprint_Relations(self::$blueprint, self::$lines, self::$executor);
        self::$pipeline = new Eduardo_Research_Manager_Greenfield_Pipeline(self::$bootstrap, self::$blueprint_pairing, self::$hydrator, self::$blueprint_relations);
        if (is_admin()) { (new Eduardo_Research_Manager_Admin())->register(); }
    }

    public static function mode(): array { return Eduardo_Research_Manager_Mode::describe(); }
    public static function greenfield(): Eduardo_Research_Manager_Greenfield { if (! self::$greenfield) { self::$greenfield = new Eduardo_Research_Manager_Greenfield(self::contract()); } return self::$greenfield; }
    public static function blueprint(): Eduardo_Research_Manager_Blueprint { if (! self::$blueprint) { self::$blueprint = new Eduardo_Research_Manager_Blueprint(); } return self::$blueprint; }
    public static function blueprint_store(): Eduardo_Research_Manager_Blueprint_Store { if (! self::$blueprint_store) { self::$blueprint_store = new Eduardo_Research_Manager_Blueprint_Store(self::blueprint()); } return self::$blueprint_store; }
    public static function compiler(): Eduardo_Research_Manager_Blueprint_Compiler { if (! self::$compiler) { self::$compiler = new Eduardo_Research_Manager_Blueprint_Compiler(self::blueprint(), self::greenfield()); } return self::$compiler; }
    public static function bootstrap(): Eduardo_Research_Manager_Bootstrap { if (! self::$bootstrap) { self::$bootstrap = new Eduardo_Research_Manager_Bootstrap(self::compiler(), self::executor()); } return self::$bootstrap; }
    public static function hydrator(): Eduardo_Research_Manager_Blueprint_Hydrator { if (! self::$hydrator) { self::$hydrator = new Eduardo_Research_Manager_Blueprint_Hydrator(self::blueprint(), self::pages(), self::executor()); } return self::$hydrator; }
    public static function blueprint_pairing(): Eduardo_Research_Manager_Blueprint_Pairing { if (! self::$blueprint_pairing) { self::$blueprint_pairing = new Eduardo_Research_Manager_Blueprint_Pairing(self::blueprint(), self::lines(), self::translations(), self::executor()); } return self::$blueprint_pairing; }
    public static function blueprint_relations(): Eduardo_Research_Manager_Blueprint_Relations { if (! self::$blueprint_relations) { self::$blueprint_relations = new Eduardo_Research_Manager_Blueprint_Relations(self::blueprint(), self::lines(), self::executor()); } return self::$blueprint_relations; }
    public static function pipeline(): Eduardo_Research_Manager_Greenfield_Pipeline { if (! self::$pipeline) { self::$pipeline = new Eduardo_Research_Manager_Greenfield_Pipeline(self::bootstrap(), self::blueprint_pairing(), self::hydrator(), self::blueprint_relations()); } return self::$pipeline; }
    public static function contract(): Eduardo_Research_Manager_Contract { if (! self::$contract) { self::$contract = new Eduardo_Research_Manager_Contract(); } return self::$contract; }
    public static function diagnostics(): Eduardo_Research_Manager_Diagnostics { if (! self::$diagnostics) { self::$diagnostics = new Eduardo_Research_Manager_Diagnostics(self::contract()); } return self::$diagnostics; }
    public static function executor(): Eduardo_Research_Manager_Executor { if (! self::$executor) { self::$executor = new Eduardo_Research_Manager_Executor(); } return self::$executor; }
    public static function pages(): Eduardo_Research_Manager_Page_Resource { if (! self::$pages) { self::$pages = new Eduardo_Research_Manager_Page_Resource(self::contract()); } return self::$pages; }
    public static function page_editor(): Eduardo_Research_Manager_Page_Editor { if (! self::$page_editor) { self::$page_editor = new Eduardo_Research_Manager_Page_Editor(self::pages(), self::executor()); } return self::$page_editor; }
    public static function insights(): Eduardo_Research_Manager_Insight_Resource { if (! self::$insights) { self::$insights = new Eduardo_Research_Manager_Insight_Resource(self::contract()); } return self::$insights; }
    public static function lines(): Eduardo_Research_Manager_Line_Resource { if (! self::$lines) { self::$lines = new Eduardo_Research_Manager_Line_Resource(self::contract()); } return self::$lines; }
    public static function outputs(): Eduardo_Research_Manager_Output_Resource { if (! self::$outputs) { self::$outputs = new Eduardo_Research_Manager_Output_Resource(self::contract()); } return self::$outputs; }
    public static function projects(): Eduardo_Research_Manager_Project_Resource { if (! self::$projects) { self::$projects = new Eduardo_Research_Manager_Project_Resource(self::contract()); } return self::$projects; }
    public static function software(): Eduardo_Research_Manager_Software_Resource { if (! self::$software) { self::$software = new Eduardo_Research_Manager_Software_Resource(self::contract()); } return self::$software; }
    public static function datasets(): Eduardo_Research_Manager_Dataset_Resource { if (! self::$datasets) { self::$datasets = new Eduardo_Research_Manager_Dataset_Resource(self::contract()); } return self::$datasets; }
    public static function rendered(): Eduardo_Research_Manager_Rendered_Verifier { if (! self::$rendered) { self::$rendered = new Eduardo_Research_Manager_Rendered_Verifier(self::contract()); } return self::$rendered; }
    public static function remediation(): Eduardo_Research_Manager_Remediation { if (! self::$remediation) { self::$remediation = new Eduardo_Research_Manager_Remediation(self::contract(), self::diagnostics(), self::pages()); } return self::$remediation; }
    public static function translations(): Eduardo_Research_Manager_Translation_Pairing { if (! self::$translations) { self::$translations = new Eduardo_Research_Manager_Translation_Pairing(); } return self::$translations; }
}
