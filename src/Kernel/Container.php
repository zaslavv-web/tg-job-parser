<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Минимальный DI-контейнер: явные фабрики + автосборка классов по типам конструктора.
 * Подмена реализации = $container->set(Interface::class, fn () => new Other()).
 */
final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @param Closure(self): mixed $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @template T
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new RuntimeException("Циклическая зависимость при сборке {$id}");
        }
        $this->resolving[$id] = true;
        try {
            $value = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->build($id);
        } finally {
            unset($this->resolving[$id]);
        }

        return $this->instances[$id] = $value;
    }

    /**
     * Создаёт новый объект (не singleton), подставляя зависимости и явные аргументы.
     *
     * @param array<string, mixed> $args аргументы конструктора по имени
     */
    public function make(string $class, array $args = []): object
    {
        return $this->build($class, $args);
    }

    /** @param array<string, mixed> $args */
    private function build(string $class, array $args = []): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Сервис не зарегистрирован и класс не найден: {$class}");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Нельзя создать {$class}: зарегистрируйте фабрику в контейнере");
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $params = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $args)) {
                $params[] = $args[$name];
                continue;
            }
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
                $params[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $params[] = $parameter->getDefaultValue();
                continue;
            }
            throw new RuntimeException("Не удалось разрешить параметр \${$name} для {$class}");
        }

        return $reflection->newInstanceArgs($params);
    }
}
