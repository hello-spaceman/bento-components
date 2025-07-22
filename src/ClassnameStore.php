<?php

namespace Bento;

use Bento\Helpers;
use Bento\Component;

/**
 * ClassnameStore
 *
 * Provides ergonomic definition and resolution of classnames for component parts.
 * Allows defining classnames via a closure or array in setup(), and resolves them
 * with current props, computed, and slots context.
 *
 * @package Bento
 */
class ClassnameStore
{
    /**
     * @var callable|array|null
     */
    protected $definition = null;

    /**
     * @var Component
     */
    protected Component $component;

    /**
     * ClassnameStore constructor.
     * @param Component $component
     */
    public function __construct(Component $component)
    {
        $this->component = $component;
    }

    /**
     * Define the classnames for this component.
     * @param callable|array $definition Closure receives ($props, $slots)
     * @return void
     */
    public function define($definition): void
    {
        $this->definition = $definition;
    }

    /**
     * Resolve and return the classnames map for all parts.
     * @return array
     */
    public function get(): array
    {
        $props = $this->component->getProps()->resolve() ?? [];
        $slots = $this->component->getSlots()->resolve() ?? [];
        $partsArr = $this->definition
            ? (is_callable($this->definition)
                ? ($this->definition)($props, $slots)
                : $this->definition)
            : [];
        return classnames($partsArr, $props, get_class($this->component));
    }
}
