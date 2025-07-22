<?php

namespace Bento;

/**
 * AttributeStore
 *
 * Provides a consistent API for defining and resolving HTML attributes for component parts.
 * Supports closure or array definitions, and resolves with current props, computed, and slots.
 *
 * Usage in Component:
 *   $this->attributes->define(function($props, $slots) {
 *       return [
 *           'root' => [
 *               ['role' => 'alert'],
 *               'hidden',
 *           ],
 *           'input' => [
 *               ['type' => 'text', 'placeholder' => 'Enter...'],
 *               'required',
 *           ],
 *       ];
 *   });
 *
 * In view:
 *   $attrs = $this->attributes->get();
 *   <div <?= $attrs['root'] ?>>
 *
 * @package Bento
 */
class AttributeStore
{
    /**
     * @var callable|array|null
     */
    protected $definition = null;

    /**
     * @var object Reference to the parent component (for props, computed, slots)
     */
    protected $component;

    /**
     * AttributesStore constructor.
     * @param object $component The parent component instance.
     */
    public function __construct($component)
    {
        $this->component = $component;
    }

    /**
     * Define the attributes for this component.
     * Accepts a closure (receives $props, $slots) or an array.
     *
     * @param callable|array $definition
     * @return void
     */
    public function define(callable|array $definition): void
    {
        $this->definition = $definition;
    }

    /**
     * Resolve and return the attributes map for all parts.
     * @return array
     */
    public function get(): array
    {
        $props = $this->component->getProps()->resolve() ?? [];
        $slots = $this->component->getSlots()->resolve() ?? [];
        $definition = $this->definition;

        $partsArr = [];
        if ($definition) {
            $partsArr = is_callable($definition)
                ? $definition($props, $slots)
                : $definition;
        }
        // Use global helper to resolve attributes
        return attributes($partsArr, $props, get_class($this->component));
    }
}
