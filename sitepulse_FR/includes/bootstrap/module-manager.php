<?php
namespace Sitepulse\Bootstrap;

if (!defined('ABSPATH')) {
    exit;
}

interface ModuleInterface {
    public function id(): string;
    public function boot(): void;
    public function register_hooks(): void;
}

final class IncludeModule implements ModuleInterface {
    /** @var string */
    private $id;

    /** @var string */
    private $path;

    /** @var callable|null */
    private $hook_registrar;

    /** @var bool */
    private $booted = false;

    public function __construct($id, $path, $hook_registrar = null) {
        $this->id = (string) $id;
        $this->path = (string) $path;
        $this->hook_registrar = is_callable($hook_registrar) ? $hook_registrar : null;
    }

    public function id(): string {
        return $this->id;
    }

    public function boot(): void {
        if ($this->booted) {
            return;
        }

        if ($this->path !== '' && file_exists($this->path)) {
            include_once $this->path;
        }

        $this->booted = true;
    }

    public function register_hooks(): void {
        if ($this->hook_registrar !== null) {
            call_user_func($this->hook_registrar);
        }
    }
}

final class ModuleManager {
    /** @var self|null */
    private static $instance = null;

    /** @var array<string, ModuleInterface> */
    private $modules = [];

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return void
     */
    public function register_from_config(array $definitions): void {
        foreach ($definitions as $id => $config) {
            $path = isset($config['path']) ? (string) $config['path'] : '';
            $hook_registrar = isset($config['hooks']) ? $config['hooks'] : null;

            $this->register(new IncludeModule($id, $path, $hook_registrar));
        }
    }

    public function register(ModuleInterface $module): void {
        $this->modules[$module->id()] = $module;
    }

    public function boot_module(string $id): bool {
        if (!isset($this->modules[$id])) {
            return false;
        }

        $module = $this->modules[$id];
        $module->boot();
        $module->register_hooks();

        return true;
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function all(): array {
        return $this->modules;
    }
}
