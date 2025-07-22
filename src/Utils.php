<?php

namespace Bento;

/**
 * Render a styled error box or HTML comment for component errors.
 *
 * @param string $class The component class name.
 * @param string $message The error message.
 * @param array $props The component props (optional).
 * @param string $trace The stack trace (optional).
 * @return string HTML for error box or HTML comment.
 */
function render_component_error($class, $message, $props = [], $trace = '')
{
   if (defined('WP_DEBUG') && WP_DEBUG && function_exists('is_user_logged_in') && is_user_logged_in()) {
      $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
      $trace = nl2br(htmlspecialchars($trace, ENT_QUOTES, 'UTF-8'));
      $props = htmlspecialchars(print_r($props, true), ENT_QUOTES, 'UTF-8');
      return "
         <div style='background:#fee;border:1px solid #c00;color:#900;padding:1em;margin:1em 0;font-family:monospace;z-index:99999;'>
            <strong>Bento Component Error:</strong> <br>
            <em>{$class}</em><br>
            <strong>Message:</strong> {$msg}<br>
            <strong>Props:</strong><pre style='white-space:pre-wrap;background:#fff3f3;border:1px solid #fcc;padding:0.5em;'>{$props}</pre>
            <strong>Stack Trace:</strong><pre style='white-space:pre-wrap;background:#fff3f3;border:1px solid #fcc;padding:0.5em;'>{$trace}</pre>
         </div>
      ";
   }
   return "<!-- Component render error: {$message} -->";
}

/**
 * Check if an array is an associative array.
 *
 * @param array $array The array to check.
 * @return bool True if the array is associative, false otherwise.
 */
function is_associative_array($array)
{
   return is_array($array) && count(array_filter(array_keys($array), 'is_string')) > 0;
}

/**
 * Check if an array is a numeric array.
 *
 * @param array $array The array to check.
 * @return bool True if the array is numeric, false otherwise.
 */
function is_numeric_array($array)
{
   return is_array($array) && count(array_filter(array_keys($array), 'is_numeric')) > 0;
}

/**
 * Recursively process attributes for nested arrays, supporting:
 * - Strings: 'disabled'
 * - Arrays: ['disabled', 'readonly']
 * - Associative arrays: ['data-foo' => 'bar', 'aria-label' => $label]
 * - Numeric arrays with children: [0] => [...], [1] => [...], ...
 * - Callables: function($props) { ... }
 *
 * @param mixed $defs
 * @param array $props
 * @param array $classesMap
 * @param string $component
 * @param string $part
 * @return mixed
 */
function process_attributes($defs, $props = [], $classesMap = [], $component = '', $part = '')
{
   // If callable, call with $props
   if (is_callable($defs)) {
      $defs = $defs($props);
   }

   // If string, treat as boolean attribute
   if (is_string($defs)) {
      $attrs = [$defs => true];
      $attrString = ' ' . esc_attr($defs);
      $attrString = trim($attrString);
      if ($component && $part) {
         $attrString = apply_filters("bento/component/{$component}/attributes/{$part}", $attrString, $attrs, $props);
      }
      return $attrString;
   }

   // If array, process based on type
   if (is_array($defs)) {
      // If associative: treat as attribute map
      if (is_associative_array($defs)) {
         $attrs = [];
         $classes = [];
         foreach ($defs as $attr => $val) {
            if ($attr === 'class') {
               $classes[] = $val;
            } elseif (is_int($attr)) {
               // Numeric keys: treat as boolean attribute
               $attrs[$val] = true;
            } elseif ($val !== null && $val !== false) {
               $attrs[$attr] = $val;
            }
         }
         // Merge in classes from classesMap if provided
         if (!empty($classesMap)) {
            $classes[] = $classesMap;
         }
         // Build class attribute if any classes exist
         $classString = implode(' ', array_unique(array_filter(array_map('trim', flatten_classes($classes)))));
         if ($classString !== '') {
            $attrs['class'] = $classString;
         }
         // Build attribute string
         $attrString = '';
         foreach ($attrs as $attr => $val) {
            if (is_bool($val)) {
               if ($val) {
                  $attrString .= ' ' . esc_attr($attr);
               }
            } elseif ($val !== null && $val !== '') {
               $attrString .= ' ' . esc_attr($attr) . '="' . esc_attr($val) . '"';
            }
         }
         $attrString = trim($attrString);

         // Filter per part
         if ($component && $part) {
            $attrString = apply_filters("bento/component/{$component}/attributes/{$part}", $attrString, $attrs, $props);
         }
         return $attrString;
      }

      // If numeric array: could be a list of attributes or nested children
      $allArrays = !empty($defs) && array_reduce($defs, function ($carry, $item) {
         return $carry && is_array($item);
      }, true);

      if ($allArrays) {
         // Nested children: process each child recursively, preserve structure
         $result = [];
         foreach ($defs as $k => $v) {
            $result[$k] = process_attributes($v, $props, $classesMap[$k] ?? [], $component, $part);
         }
         return $result;
      } else {
         // Flat list of attributes (e.g., ['disabled', 'readonly'])
         $attrs = [];
         $classes = [];
         foreach ($defs as $def) {
            if (is_callable($def)) {
               $def = $def($props);
            }
            if (is_array($def)) {
               // Recursively process associative arrays or nested children
               if (is_associative_array($def)) {
                  $attrs = array_merge($attrs, $def);
               } else {
                  // If numeric, flatten and merge
                  $attrs = array_merge($attrs, (array)process_attributes($def, $props, [], $component, $part));
               }
            } elseif (is_string($def)) {
               $attrs[$def] = true;
            }
         }
         // Merge in classes from classesMap if provided
         if (!empty($classesMap)) {
            $classes[] = $classesMap;
         }
         // Build class attribute if any classes exist
         $classString = implode(' ', array_unique(array_filter(array_map('trim', flatten_classes($classes)))));
         if ($classString !== '') {
            $attrs['class'] = $classString;
         }
         // Build attribute string
         $attrString = '';
         foreach ($attrs as $attr => $val) {
            if (is_bool($val)) {
               if ($val) {
                  $attrString .= ' ' . esc_attr($attr);
               }
            } elseif ($val !== null && $val !== '') {
               $attrString .= ' ' . esc_attr($attr) . '="' . esc_attr($val) . '"';
            }
         }
         $attrString = trim($attrString);

         // Filter per part
         if ($component && $part) {
            $attrString = apply_filters("bento/component/{$component}/attributes/{$part}", $attrString, $attrs, $props);
         }
         return $attrString;
      }
   }

   return '';
}

/**
 * Build a map of attribute strings for component parts, supporting nested per-item arrays.
 *
 * Accepts:
 * - Strings: 'disabled'
 * - Arrays: ['disabled', 'readonly']
 * - Associative arrays: ['data-foo' => 'bar', 'aria-label' => $label]
 * - Numeric arrays with children: [0] => [...], [1] => [...], ...
 * - Callables: function($props) { ... }
 *
 * Example:
 *   $attrs = attributes([
 *     'root' => [
 *       ['data-id' => 123, 'aria-label' => 'Alert'],
 *       'hidden',
 *     ],
 *     'input' => [
 *       ['type' => 'text', 'placeholder' => 'Enter...'],
 *       'required',
 *     ],
 *     'item' => function($props) {
 *       $result = [];
 *       foreach ($props['items'] as $i => $item) {
 *         $result[$i] = [
 *           'data-index' => $i,
 *           ['active' => $item['active']],
 *         ];
 *       }
 *       return $result;
 *     }
 *   ], $props, 'Alert');
 *
 * @param array $parts      ['part' => [ ...attribute args... ], ...]
 * @param array $props      (optional) Props for filtering.
 * @param string $component (optional) For filter naming.
 * @param array $classesMap (optional) Map of classes to merge in as 'class' attribute.
 * @return array            ['part' => 'attr string', ...] or nested arrays
 */
function attributes(array $parts, array $props = [], string $component = '', array $classesMap = []): array
{
   $result = [];
   foreach ($parts as $part => $defs) {
      $map = $classesMap[$part] ?? [];
      $attrList = process_attributes($defs, $props, $map, $component, $part);
      $result[$part] = build_attributes($attrList, $part);
   }
   // Filter the entire map
   if ($component) {
      $result = apply_filters("bento/component/{$component}/attributes", $result, $props);
   }
   return $result;
}

/**
 * Recursively build attribute strings for attributes output.
 *
 * @param mixed $attrList
 * @param string $part
 * @return mixed
 */
function build_attributes($attrList, $part = '')
{
   if (!is_array($attrList)) {
      return $attrList;
   }
   if (empty($attrList)) {
      return '';
   }

   $allStrings = true;
   $allArrays = true;

   foreach ($attrList as $v) {
      if (!is_string($v)) $allStrings = false;
      if (!is_array($v)) $allArrays = false;
   }

   if ($allStrings) {
      // Join all attribute strings with a space (attributes are already formatted as strings)
      return trim(implode(' ', array_filter($attrList)));
   } elseif ($allArrays) {
      $result = [];
      foreach ($attrList as $k => $v) {
         $result[$k] = build_attributes($v, $part . "[$k]");
      }
      return $result;
   } else {
      trigger_error("attributes: Part '$part' has a mix of string and array children. This is not supported.", E_USER_WARNING);
      return '';
   }
}

/**
 * Helper to flatten classes input (string|array|array of arrays).
 *
 * @param mixed $classes
 * @return array
 */
function flatten_classes($classes): array
{
   $result = [];
   if (is_array($classes)) {
      foreach ($classes as $c) {
         $result = array_merge($result, flatten_classes($c));
      }
   } elseif (is_string($classes)) {
      $result[] = $classes;
   }
   return $result;
}

/**
 * Escape attribute value for safe HTML output.
 * Uses WordPress's esc_attr if available, otherwise htmlspecialchars.
 *
 * @param string $value
 * @return string
 */
function esc_attr($value): string
{
   if (function_exists('esc_attr')) {
      return \esc_attr($value);
   }
   return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Build a BEM/ABEM class name.
 *
 * @param string $element   The element name (e.g., 'title').
 * @param string $block     The block name (e.g., 'card').
 * @param string $modifier  The modifier (e.g., 'large').
 * @param array $options    ['separator' => '__', 'mod_separator' => '--']
 * @return string
 */
function block_class(
   string $element,
   string $block,
   string $modifier = '',
   array $options = []
): string {
   $separator = $options['separator'] ?? '__';
   $mod_separator = $options['mod_separator'] ?? '--';

   if (!$block) {
      trigger_error('block_class: No block name provided.', E_USER_WARNING);
      return '';
   }

   $class = $block;
   if ($element !== '') {
      $class .= $separator . $element;
   }
   if ($modifier !== '') {
      $class .= $mod_separator . $modifier;
   }
   return $class;
}

/**
 * Build a map of class strings for component parts, supporting nested per-item arrays.
 *
 * @param array $parts      ['part' => [ ...classnames args... ], ...]
 * @param array $props      (optional) Props for filtering.
 * @param string $component (optional) For filter naming.
 * @return array|bool           ['part' => 'class string', ...] or nested arrays
 */
function classnames(array $parts, array $props = [], string $component = ''): array|bool
{
   $result = [];
   foreach ($parts as $part => $defs) {
      $classList = process_class_part($defs, $props);
      // At the leaf, join as string
      if (is_array($classList)) {
         // If not a numeric array, something went wrong. Return nothing.
         if (!is_numeric_array($classList)) {
            return false;
         }
         $result[$part] = build_classes($classList, $part);
      } else {
         $result[$part] = $classList;
      }
      if ($component && $part && is_string($result[$part])) {
         $result[$part] = apply_filters("bento/component/{$component}/class/{$part}", $result[$part], (array)$classList, $props);
      }
   }
   if ($component) {
      $result = apply_filters("bento/component/{$component}/classes", $result, $props);
   }
   return $result;
}

/**
 * Recursively build class strings for classnames output.
 *
 * @param mixed $classList
 * @param string $part
 * @param bool $wrap
 * @return mixed
 */
function build_classes($classList, $part = '', $wrap = true)
{
   if (!is_array($classList)) {
      return $classList;
   }
   if (empty($classList)) {
      return '';
   }

   $allStrings = true;
   $allArrays = true;

   foreach ($classList as $v) {
      if (!is_string($v)) $allStrings = false;
      if (!is_array($v)) $allArrays = false;
   }

   if ($allStrings) {
      $classString = implode(' ', array_unique(array_filter(flatten_classes($classList))));
      if ($wrap && $classString !== '') {
         return 'class="' . $classString . '"';
      }
      return $classString;
   } elseif ($allArrays) {
      $result = [];
      foreach ($classList as $k => $v) {
         $result[$k] = build_classes($v, $part . "[$k]", $wrap);
      }
      return $result;
   } else {
      trigger_error("classnames: Part '$part' has a mix of string and array children. This is not supported.", E_USER_WARNING);
      return '';
   }
}

/**
 * Recursively process class part for classnames helper.
 *
 * @param mixed $def
 * @param array $props
 * @param array $slots
 * @return array|string
 */
function process_class_part($def, $props = [], $slots = [])
{
   if (is_callable($def)) {
      return process_class_part($def($props, $slots), $props, $slots);
   }
   if (is_string($def)) {
      return [$def];
   }
   if (is_array($def)) {
      // Associative: conditional classes
      if (is_associative_array($def)) {
         $result = [];
         foreach ($def as $class => $cond) {
            if (is_int($class)) {
               $result[] = $cond;
            } elseif ($cond) {
               $result[] = $class;
            }
         }
         return $result;
      } elseif (is_numeric_array($def)) {
         // Check if all children are arrays (nested per-item)
         $allChildrenAreArrays = !empty($def) && array_reduce($def, function ($carry, $item) {
            return $carry && is_array($item);
         }, true);
         if ($allChildrenAreArrays) {
            // Preserve structure: process each child, don't flatten
            $result = [];
            foreach ($def as $k => $item) {
               $result[$k] = process_class_part($item, $props, $slots);
            }
            return $result;
         } else {
            // Flatten and merge
            $result = [];
            foreach ($def as $item) {
               $result = array_merge($result, process_class_part($item, $props, $slots));
            }
            return $result;
         }
      }
   }
   return [];
}
