<?php

namespace Bento;

use InvalidArgumentException;

/**
 * Class PropStore
 *
 * Stores and manages component props, including definition, defaults, validation, and formatting.
 * Supports array and property access for ergonomic DX.
 *
 * @package Bento
 * @implements \ArrayAccess<mixed,mixed>
 */
class PropStore implements \ArrayAccess
{
    /**
     * The component class name.
     * @var string
     */
    protected string $component;

    /**
     * The current prop values.
     * @var array
     */
    protected array $props = [];

    /**
     * The prop definitions (default, type, validator, formatter).
     * @var array
     */
    protected array $definitions = [];

    /**
     * Computed property closures.
     * @var array<string,Closure>
     */
    protected array $computed = [];

    /**
     * Computed property cache (per render).
     * @var array<string,mixed>
     */
    protected array $computedCache = [];

    /**
     * PropStore constructor.
     * @param string $component
     * @param array $initial
     */
    public function __construct($component, $initial = [])
    {
        $this->component = $component;
        $this->props = $initial;
    }

    /**
     * Define expected props and their rules.
     *
     * Example:
     *   [
     *     'title' => ['Default Title', 'string', false, false, 'Title', true],
     *     'count' => [0, 'integer', fn($v) => $v >= 0, null, 'Count', true],
     *   ]
     *
     * @param array $definitions
     * @return void
     */
    public function define($definitions)
    {
        // Global props always available unless overridden
        $globalProps = [
            'id' => [
                'default'   => '',
                'type'      => ['string'],
                'validator' => null,
                'formatter' => null,
                'required'  => false,
            ],
            'block_name' => [
                'default'   => '',
                'type'      => ['string'],
                'validator' => null,
                'formatter' => null,
                'required'  => false,
            ],
            'classes' => [
                'default'   => '',
                'type'      => ['string', 'array'],
                'validator' => null,
                'formatter' => null,
                'required'  => false,
            ],
            'attributes' => [
                'default'   => '',
                'type'      => ['string', 'array'],
                'validator' => null,
                'formatter' => null,
                'required'  => false,
            ],
            'resetClasses' => [
                'default'   => [],
                'type'      => ['array'],
                'validator' => null,
                'formatter' => null,
                'required'  => false,
            ],
            'resetAttributes' => [
                'default'   => [],
                'type'      => ['array'],
                'validator' => null,
                'formatter' => null,
                'required'  => false,
            ],
        ];
        $definitions = array_merge($globalProps, $definitions);


        foreach ($definitions as $key => $config) {
            $default = $type = $validator = $formatter = null;
            $required = false;
            if (!is_array($config)) {
                // Scalar default value
                $default = $config;
            } elseif (array_keys($config) !== range(0, count($config) - 1)) {
                // Associative array: use keys, order doesn't matter
                $default   = isset($config['default']) ? $config['default'] : null;
                $type      = $config['type']      ?? null;
                $required  = $config['required']  ?? false;
                $validator = $config['validator'] ?? null;
                $formatter = $config['formatter'] ?? null;
            } else {
                // Numeric array: [default, type, required, validator, formatter]
                [$default, $type, $required, $validator, $formatter] = array_pad($config, 5, null);
                $required = (bool)$required;
            }
            $this->definitions[$key] = compact('default', 'type', 'required', 'validator', 'formatter');
        }
        $this->applyDefaultsAndValidate();
    }

    /**
     * Apply defaults, validate, and format all props.
     * @return void
     */
    protected function applyDefaultsAndValidate()
    {
        foreach ($this->definitions as $key => $def) {
            $prop_is_defined = array_key_exists($key, $this->props);
            $value = $prop_is_defined ? $this->props[$key] : $def['default'];

            // Check required: if required, no value, and no default, throw error
            if (
                isset($def['required']) && $def['required'] &&
                !$prop_is_defined &&
                ($def['default'] === null || $def['default'] === '')
            ) {
                throw new InvalidArgumentException("Required prop '$key' is missing and no default is set.");
            }

            $value = apply_filters("bento/component/{$this->component}/prop/{$key}", $value);

            if ($def['type']) {
                $allowed = (array) $def['type'];
                $valid = in_array(gettype($value), $allowed, true);
                if (!$valid) {
                    throw new InvalidArgumentException("Prop '$key' must be of type " . implode('|', $allowed));
                }
            }
            if ($def['validator'] && is_callable($def['validator'])) {
                if (!$def['validator']($value)) {
                    throw new InvalidArgumentException("Validation failed for prop '$key'");
                }
            }
            if ($def['formatter'] && is_callable($def['formatter'])) {
                $value = $def['formatter']($value);
            }
            $this->props[$key] = $value;
        }
    }

    /**
     * Register a computed property.
     *
     * @param string $key
     * @param Closure $fn
     * @return void
     */
    public function computed($key, $fn)
    {
        $this->computed[$key] = $fn;
    }

    /**
     * Clear computed property cache (should be called before each render).
     * @return void
     */
    public function clearComputedCache()
    {
        $this->computedCache = [];
    }

    /**
     * Get a prop value (including computed).
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->computed)) {
            if (!array_key_exists($key, $this->computedCache)) {
                // Pass all props (including computed) to the closure
                $this->computedCache[$key] = ($this->computed[$key])($this);
            }
            return $this->computedCache[$key];
        }
        return $this->props[$key] ?? $default;
    }

    /**
     * Magic getter for prop access (including computed).
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * ArrayAccess: offsetGet (including computed).
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * ArrayAccess: offsetSet (disallowed, props are read-only)
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        throw new \LogicException('Props are read-only.');
    }

    /**
     * ArrayAccess: offsetExists
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->props[$offset]);
    }

    /**
     * ArrayAccess: offsetUnset (disallowed, props are read-only)
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        throw new \LogicException('Props are read-only.');
    }

    /**
     * Extract a set of props as an associative array for use in views.
     *
     * Example:
     *   extract($this->props->extractProps(['title', 'subtitle']));
     *
     * @param array $keys
     * @return array<string, mixed>
     */
    public function extractProps($keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Resolve all props as an associative array for use in views.
     *
     * Example:
     *   extract($this->props->resolve());
     *
     * @return array<string, mixed>
     */
    public function resolve()
    {
        $result = [];
        foreach (array_keys($this->definitions) as $key) {
            $result[$key] = $this->get($key);
        }
        // Include computed properties
        foreach ($this->computed as $key => $fn) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = $this->get($key);
            }
        }
        return $result;
    }
}
