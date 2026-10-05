<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Navigation_Builder {

    /**
     * Builds a parent-child tree from a flat list of items.
     *
     * @param array  $items      List of items, each having parent and unique ID keys.
     * @param string $id_key     Key for the unique ID.
     * @param string $parent_key Key for the parent ID.
     * @param string $children_key Key where children will be stored.
     * @return array The hierarchical tree.
     */
    public static function build_tree(array $items, $id_key = 'post_id', $parent_key = 'parent_id', $children_key = 'children') {
        $map = [];
        foreach ($items as $item) {
            $item[$children_key] = [];
            $map[$item[$id_key]] = $item;
        }

        $tree = [];
        foreach (array_keys($map) as $id) {
            $parent_id = $map[$id][$parent_key] ?? 0;
            if ($parent_id && isset($map[$parent_id])) {
                $map[$parent_id][$children_key][] = &$map[$id];
            } else {
                $tree[] = &$map[$id];
            }
        }

        return $tree;
    }

    /**
     * Recursively renders a tree of items as an HTML list (ul/li).
     *
     * @param array  $tree      The tree structure to render.
     * @param string $active_id The ID of the currently active item.
     * @param array  $keys      Custom keys mapping for rendering (id, title, url, children).
     * @return string HTML output.
     */
    public static function render_tree(array $tree, $active_id, array $keys = []) {
        $keys = wp_parse_args($keys, [
            'id'       => 'id',
            'title'    => 'title',
            'url'      => 'url',
            'children' => 'children',
        ]);

        $id_key       = $keys['id'];
        $title_key    = $keys['title'];
        $url_key      = $keys['url'];
        $children_key = $keys['children'];

        $html = '<ul>';
        foreach ($tree as $item) {
            $item_id    = $item[$id_key] ?? '';
            $item_url   = $item[$url_key] ?? '#';
            $item_title = $item[$title_key] ?? '';
            
            $is_active    = ($item_id === $active_id);
            $active_class = $is_active ? ' class="is-active" aria-current="page"' : '';

            $html .= '<li>';
            $html .= '<a href="' . esc_url($item_url) . '"' . $active_class . '>' . esc_html($item_title) . '</a>';

            if (!empty($item[$children_key]) && is_array($item[$children_key])) {
                $html .= self::render_tree($item[$children_key], $active_id, $keys);
            }

            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
