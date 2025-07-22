<?php

namespace Bento;

use InvalidArgumentException;

/**
 * Class SlotStore
 *
 * Stores and manages component slots, including type enforcement and callable/string support.
 *
 * @package Bento
 * @implements \ArrayAccess<mixed,mixed>
 */
class SlotStore implements \ArrayAccess
{
    /**
     * @var string
     */
    protected string $component;

    /**
     * @var array<string, string|callable>
     */
    protected array $slots = [];

    /**
     * @var array<string, array>
     */
    protected array $definitions = [];

    /**
     * SlotStore constructor.
     * @param string $component The component class name (required for WP filtering).
     */
    public function __construct(string $component)
    {
        $this->component = $component;
    }



    /**
     * Define expected slot names and allowed types.
     *
     * @param array<string, string|array> $definitions
     * @return void
     */
    public function define(array $definitions): void
    {
        foreach ($definitions as $name => $type) {
            $this->definitions[$name] = (array) $type;
        }
    }

    /**
     * Set the content for a slot.
     * Supports default slot (null or '') and override/append behavior.
     *
     * @param string|null $name
     * @param string|callable $content
     * @param bool $override If true (default), replaces slot content. If false, appends.
     * @return void
     */
    public function set($name, $content, bool $override = true): void
    {
        $slotName = ($name === null || $name === '') ? 'default' : $name;

        if (isset($this->definitions[$slotName])) {
            $allowed = $this->definitions[$slotName];
            $actual = is_callable($content) ? 'callable' : gettype($content);
            if (!in_array($actual, $allowed, true)) {
                throw new InvalidArgumentException("Slot '{$slotName}' must be of type " . implode('|', $allowed));
            }
        }

        if ($override || !isset($this->slots[$slotName])) {
            $this->slots[$slotName] = [$content];
        } else {
            $this->slots[$slotName][] = $content;
        }
    }

    /**
     * Check if a slot is defined.
     *
     * @param string|null $name
     * @return bool
     */
    public function has(string|null $name): bool
    {
        $slotName = ($name === null || $name === '') ? 'default' : $name;
        return isset($this->slots[$slotName]);
    }

    /**
     * Check if a slot is empty (not set, or all contents are empty).
     * @param string|null $name
     * @return bool
     */
    public function isEmpty(string|null $name): bool
    {
        $slotName = ($name === null || $name === '') ? 'default' : $name;
        if (!$this->has($slotName)) {
            return true;
        }
        foreach ($this->slots[$slotName] as $slot) {
            $content = is_callable($slot) ? $slot() : $slot;
            if (trim($content) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if a slot exists and is not empty.
     * @param string|null $name
     * @return bool
     */
    public function isActive($name): bool
    {
        return $this->has($name) && !$this->isEmpty($name);
    }

    /**
     * Get the slot content, executing callables (with scope) and concatenating all.
     * Applies a WP filter for child theme extensibility.
     *
     * @param string|null $name
     * @param string $fallback
     * @param array $scope Data to pass to slot closure (scoped slots)
     * @return string
     */
    public function get($name = null, string $fallback = '', array $scope = []): string
    {
        $slotName = ($name === null || $name === '') ? 'default' : $name;
        if (!$this->has($slotName)) {
            return $fallback;
        }
        $output = '';
        foreach ($this->slots[$slotName] as $slot) {
            if (is_callable($slot)) {
                // Pass scope as arguments (unpacked)
                $content = call_user_func_array($slot, array_values($scope));
            } else {
                $content = $slot;
            }
            $content = apply_filters("bento/component/{$this->component}/slot/{$slotName}", $content);
            $output .= $content;
        }
        return $output;
    }

    /**
     * Resolve all slots and return an array of their content.
     *
     * @return array
     */
    public function resolve(): array
    {
        $result = [];
        foreach (array_keys($this->definitions) as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Magic getter for slot access.
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * ArrayAccess: offsetGet
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * ArrayAccess: offsetSet
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * ArrayAccess: offsetExists
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * ArrayAccess: offsetUnset
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset($offset): void
    {
        unset($this->slots[$offset]);
    }
}
