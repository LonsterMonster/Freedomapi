<?php
if (!defined('ABSPATH')) exit;

/**
 * Canonical loader for FreedomAPI's own platform documentation.
 * Publisher-created API documentation remains in Documentation Workspace.
 */
class APIPlatform_Platform_Documentation {

    private static $documents = null;
    private static $render_cache = [];
    private static $base_url = '';

    private static function root(){
        return trailingslashit(defined('APIPLATFORM_PATH') ? APIPLATFORM_PATH . 'docs' : dirname(__DIR__, 2) . '/docs');
    }

    private static function order(){
        return [
            'README.md' => ['label' => 'Documentation Home', 'group' => 'Getting Started', 'visibility' => 'public'],
            'getting-started.md' => ['label' => 'Getting Started', 'group' => 'Getting Started', 'visibility' => 'public'],
            'publisher-guide.md' => ['label' => 'Publisher Guide', 'group' => 'Publisher Guide', 'visibility' => 'public'],
            'developer-guide.md' => ['label' => 'API Consumer Guide', 'group' => 'Developer Guide', 'visibility' => 'public'],
            'developer-portal.md' => ['label' => 'Developer Portal', 'group' => 'Developer Portal', 'visibility' => 'public'],
            'administration.md' => ['label' => 'Administration', 'group' => 'Administration', 'visibility' => 'internal'],
            'architecture.md' => ['label' => 'Architecture', 'group' => 'Architecture', 'visibility' => 'internal'],
            'database.md' => ['label' => 'Database', 'group' => 'Architecture', 'visibility' => 'internal'],
            'request-history.md' => ['label' => 'Request History and Retention', 'group' => 'Operations', 'visibility' => 'public'],
            'operations.md' => ['label' => 'Operations', 'group' => 'Operations', 'visibility' => 'internal'],
            'frontend.md' => ['label' => 'Frontend Architecture', 'group' => 'Architecture', 'visibility' => 'internal'],
            'troubleshooting.md' => ['label' => 'Troubleshooting', 'group' => 'Troubleshooting', 'visibility' => 'public'],
            'glossary.md' => ['label' => 'Glossary and FAQ', 'group' => 'Reference', 'visibility' => 'public'],
            'security/SECURITY_ARCHITECTURE.md' => ['label' => 'Security Architecture', 'group' => 'Security', 'visibility' => 'internal'],
        ];
    }

    private static function slug($path){
        return sanitize_title(str_replace(['/', '.md'], ['-', ''], strtolower($path)));
    }

    private static function discover(){
        if (self::$documents !== null) return self::$documents;
        self::$documents = [];
        $root = self::root();
        if (!is_dir($root)) return self::$documents;

        foreach (self::order() as $path => $meta) {
            $absolute = $root . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_file($absolute) || strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) !== 'md') continue;
            $id = self::slug($path);
            if ($id === '') continue;
            self::$documents[$id] = [
                'id' => $id,
                'path' => $path,
                'title' => $meta['label'],
                'group' => $meta['group'],
                'visibility' => $meta['visibility'],
                'url' => '',
            ];
        }
        return self::$documents;
    }

    private static function visible(array $doc){
        return ($doc['visibility'] ?? 'public') !== 'internal' || current_user_can('manage_options');
    }

    public static function sections($base_url){
        self::$base_url = (string) $base_url;
        $groups = [];
        foreach (self::discover() as $doc) {
            if (!self::visible($doc)) continue;
            if (!isset($groups[$doc['group']])) {
                $groups[$doc['group']] = ['id' => sanitize_title($doc['group']), 'label' => $doc['group'], 'description' => '', 'posts' => [], 'endpoints' => []];
            }
            $doc['url'] = add_query_arg('doc', $doc['id'], $base_url);
            $groups[$doc['group']]['posts'][] = [
                'id' => $doc['id'],
                'title' => $doc['title'],
                'description' => $doc['visibility'] === 'internal' ? 'Maintainer and administrator reference.' : 'FreedomAPI platform guide.',
                'url' => $doc['url'],
                'markdown' => true,
                'visibility' => $doc['visibility'],
            ];
        }
        return array_values($groups);
    }

    public static function document($id){
        $id = sanitize_key($id);
        $documents = self::discover();
        if ($id === '' || empty($documents[$id])) return null;
        if (!self::visible($documents[$id])) return null;
        if (!array_key_exists($id, self::$render_cache)) {
            $doc = $documents[$id];
            $absolute = self::root() . str_replace('/', DIRECTORY_SEPARATOR, $doc['path']);
            $raw = @file_get_contents($absolute);
            if (!is_string($raw)) return null;
            $doc['content'] = self::render_markdown($raw, $id);
            $doc['toc'] = self::$documents[$id]['toc'] ?? [];
            self::$render_cache[$id] = $doc;
        }
        return self::$render_cache[$id];
    }

    public static function navigation_toc($id){
        $doc = self::document($id);
        return $doc['toc'] ?? [];
    }

    public static function adjacent($id){
        $id = sanitize_key($id);
        $readme_id = self::slug('README.md');
        $visible = [];
        foreach (self::discover() as $doc) {
            if ($doc['id'] === $readme_id || !self::visible($doc)) continue;
            $doc['url'] = add_query_arg('doc', $doc['id'], self::$base_url);
            $visible[] = $doc;
        }

        foreach ($visible as $index => $doc) {
            if ($doc['id'] !== $id) continue;
            return [
                'previous' => $visible[$index - 1] ?? null,
                'next' => $visible[$index + 1] ?? null,
            ];
        }

        return ['previous' => null, 'next' => null];
    }

    private static function render_markdown($markdown, $current_id){
        $lines = preg_split('/\r\n|\r|\n/', (string) $markdown);
        $html = '';
        $toc = [];
        $in_code = false;
        $code_lang = '';
        $code = [];
        $list = null;
        $seen_h1 = false;
        $flush_list = function() use (&$html, &$list){
            if ($list) { $html .= '</' . $list . '>'; $list = null; }
        };
        for ($line_index = 0, $line_count = count($lines); $line_index < $line_count; $line_index++) {
            $line = $lines[$line_index];
            if (preg_match('/^```\s*([A-Za-z0-9_-]*)\s*$/', $line, $m)) {
                if ($in_code) {
                    $safe_language = sanitize_key($code_lang);
                    $pre_attributes = $safe_language !== '' ? ' data-language="' . esc_attr($safe_language) . '"' : '';
                    $class = $safe_language !== '' ? ' class="language-' . esc_attr($safe_language) . '"' : '';
                    $html .= '<pre' . $pre_attributes . '><code' . $class . '>' . esc_html(implode("\n", $code)) . '</code></pre>';
                    $in_code = false; $code = []; $code_lang = '';
                } else { $flush_list(); $in_code = true; $code_lang = $m[1] ?? ''; }
                continue;
            }
            if ($in_code) { $code[] = $line; continue; }
            if (trim($line) === '') { $flush_list(); continue; }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $line, $m)) {
                $flush_list(); $level = strlen($m[1]); $text = trim($m[2]); $slug = sanitize_title($text); $base = $slug; $n = 2;
                if ($level === 1 && !$seen_h1) {
                    $seen_h1 = true;
                    continue;
                }
                if ($level === 1) $seen_h1 = true;
                while (isset($toc[$slug])) $slug = $base . '-' . $n++;
                $toc[$slug] = ['id' => $slug, 'level' => $level, 'title' => wp_strip_all_tags(self::inline($text, $current_id))];
                $html .= '<h' . $level . ' id="' . esc_attr($slug) . '">' . self::inline($text, $current_id) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^\s*(---+|\*\*\*+)\s*$/', $line)) { $flush_list(); $html .= '<hr>'; continue; }
            if (preg_match('/^>\s?(.*)$/', $line, $m)) { $flush_list(); $html .= '<blockquote>' . self::inline($m[1], $current_id) . '</blockquote>'; continue; }
            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m)) { if ($list !== 'ul') { $flush_list(); $html .= '<ul>'; $list = 'ul'; } $html .= '<li>' . self::inline($m[1], $current_id) . '</li>'; continue; }
            if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $m)) { if ($list !== 'ol') { $flush_list(); $html .= '<ol>'; $list = 'ol'; } $html .= '<li>' . self::inline($m[1], $current_id) . '</li>'; continue; }
            if (strpos($line, '|') !== false && isset($lines[$line_index + 1]) && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$line_index + 1])) {
                $flush_list();
                $header_cells = array_values(array_filter(array_map('trim', explode('|', trim($line, " |"))), 'strlen'));
                $line_index += 2;
                $body_rows = [];
                while ($line_index < $line_count && strpos($lines[$line_index], '|') !== false && trim($lines[$line_index]) !== '') {
                    $body_rows[] = array_values(array_filter(array_map('trim', explode('|', trim($lines[$line_index], " |"))), 'strlen'));
                    $line_index++;
                }
                $line_index--;
                $html .= '<div class="apiplatform-docs-markdown-table-wrap"><table><thead><tr>';
                foreach ($header_cells as $cell) $html .= '<th>' . self::inline($cell, $current_id) . '</th>';
                $html .= '</tr></thead><tbody>';
                foreach ($body_rows as $row) {
                    $html .= '<tr>'; foreach ($row as $cell) $html .= '<td>' . self::inline($cell, $current_id) . '</td>'; $html .= '</tr>';
                }
                $html .= '</tbody></table></div>';
                continue;
            }
            if (strpos($line, '|') !== false && preg_match('/^\s*\|?\s*:?-{3,}/', $line)) continue;
            if (strpos($line, '|') !== false) {
                $flush_list(); $cells = array_values(array_filter(array_map('trim', explode('|', trim($line, " |"))), 'strlen'));
                if ($cells) { $html .= '<div class="apiplatform-docs-markdown-table-wrap"><table><tbody><tr>'; foreach ($cells as $cell) $html .= '<td>' . self::inline($cell, $current_id) . '</td>'; $html .= '</tr></tbody></table></div>'; }
                continue;
            }
            $flush_list(); $html .= '<p>' . self::inline($line, $current_id) . '</p>';
        }
        if ($in_code) $html .= '<pre><code>' . esc_html(implode("\n", $code)) . '</code></pre>';
        $flush_list();
        $documents = self::$documents; if (isset($documents[$current_id])) $documents[$current_id]['toc'] = array_values($toc); self::$documents = $documents;
        return $html;
    }

    private static function inline($text, $current_id){
        $text = esc_html((string) $text);
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function($m){
            return self::approved_image($m[1], html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        }, $text);
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) use ($current_id){
            $label = $m[1]; $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (preg_match('/^\.?\/?([^#]+\.md)(#[A-Za-z0-9_-]+)?$/i', $url, $match)) {
                $path = ltrim(str_replace('\\', '/', $match[1]), './'); $target = null;
                foreach (self::discover() as $doc) if (strcasecmp($doc['path'], $path) === 0) { $target = $doc; break; }
                if (!$target || !self::visible($target)) return esc_html($label);
                $href = add_query_arg('doc', $target['id'], self::$base_url ?: remove_query_arg('doc', wp_unslash($_SERVER['REQUEST_URI'] ?? '')));
                if (!empty($match[2])) $href .= $match[2];
                return '<a href="' . esc_url($href) . '">' . esc_html($label) . '</a>';
            }
            if (strpos($url, '#') === 0) return '<a href="' . esc_attr($url) . '">' . esc_html($label) . '</a>';
            $safe = esc_url($url, ['http', 'https', 'mailto']);
            return $safe ? '<a href="' . $safe . '">' . esc_html($label) . '</a>' : esc_html($label);
        }, $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
        return preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);
    }

    private static function approved_image($alt, $url){
        if (!preg_match('/^assets\/public\/[A-Za-z0-9._\/-]+\.(?:png|jpe?g|gif|webp|svg)$/i', $url)) {
            return '';
        }

        $relative = str_replace('/', DIRECTORY_SEPARATOR, $url);
        $absolute = self::root() . $relative;
        if (!is_file($absolute)) return '';

        $src = defined('APIPLATFORM_URL') ? trailingslashit(APIPLATFORM_URL) . 'docs/' . ltrim($url, '/') : '';
        if ($src === '') return '';
        return '<img src="' . esc_url($src) . '" alt="' . esc_attr(wp_strip_all_tags($alt)) . '" loading="lazy">';
    }
}
