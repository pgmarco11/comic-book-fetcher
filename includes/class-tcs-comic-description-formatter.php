<?php
defined('ABSPATH') || exit;

/** Formats provider descriptions without owning catalog queries or background scans. */
class TCS_Comic_Description_Formatter {
    public function normalize_publisher_description(
        $description,
        $fallback = 'No description available.'
    ) {
        $description = html_entity_decode(
            (string) $description,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $description = preg_replace(
            [
                '#<br\s*/?>#i',
                '#</p\s*>#i',
                '#</h[1-6]\s*>#i',
                '#</li\s*>#i',
                '#</(?:div|ul|ol)\s*>#i',
            ],
            [
                ' ',
                ' ',
                ': ',
                ' ',
                ' ',
            ],
            $description
        );

        $description = wp_strip_all_tags(
            $description,
            true
        );

        $description = preg_replace(
            '/\s+/u',
            ' ',
            $description
        );

        $description = rtrim(
            trim($description),
            " ;"
        );

        return $description !== ''
            ? $description
            : $fallback;
    }

    public function clean_cv_description( $desc ) {

            if ( empty( $desc ) ) {
                return '';
            }

            $desc = str_replace(
                ['Ã€', 'Ã', 'Ã‚', 'Ãƒ', 'Ã„', 'Ã…', 'Ã†', 'Ã‡', 'Ãˆ', 'Ã‰', 'ÃŠ', 'Ã‹', 'ÃŒ', 'Ã', 'ÃŽ', 'Ã', 'Ã', 'Ã‘', 'Ã’', 'Ã“', 'Ã”', 'Ã•', 'Ã–', 'Ã—', 'Ã˜', 'Ã™', 'Ãš', 'Ã›', 'Ãœ', 'Ã', 'Ãž', 'ÃŸ',
                 'Ã ', 'Ã¡', 'Ã¢', 'Ã£', 'Ã¤', 'Ã¥', 'Ã¦', 'Ã§', 'Ã¨', 'Ã©', 'Ãª', 'Ã«', 'Ã¬', 'Ã­', 'Ã®', 'Ã¯', 'Ã°', 'Ã±', 'Ã²', 'Ã³', 'Ã´', 'Ãµ', 'Ã¶', 'Ã·', 'Ã¸', 'Ã¹', 'Ãº', 'Ã»', 'Ã¼', 'Ã½', 'Ã¾', 'Ã¿'],
                ['À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ', 'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ð', 'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', '×', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'Þ', 'ß',
                 'à', 'á', 'â', 'ã', 'ä', 'å', 'æ', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ð', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', '÷', 'ø', 'ù', 'ú', 'û', 'ü', 'ý', 'þ', 'ÿ'],
                $desc
            );

            /* -------------------------------------------------
             * 1. Encoding Normalization (NO GUESSING)
             * ------------------------------------------------- */

            // Remove Unicode replacement characters FIRST
            $desc = str_replace("\xEF\xBF\xBD", '', $desc);

            // Decode HTML entities
            $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Only convert if the string is not valid UTF-8
            if (!mb_check_encoding($desc, 'UTF-8')) {
                $desc = mb_convert_encoding($desc, 'UTF-8', 'Windows-1252');
            }

            // Normalize Unicode composition
            if (class_exists('Normalizer')) {
                $desc = Normalizer::normalize($desc, Normalizer::FORM_C);
            }

            // Remove control characters
            $desc = preg_replace('/[^\P{C}\n]+/u', '', $desc);

            /*
            * Comic Vine sometimes supplies a lazy-loaded image followed by
            * the same image inside <noscript>. The primary image is normalized
            * below, so remove the duplicate fallback block first.
            */
            $desc = preg_replace(
                '#<noscript\b[^>]*>.*?</noscript>#is',
                '',
                $desc
            );

            $desc = preg_replace_callback(
                '#<img\b[^>]*>#is',
                static function ($matches) {
                    $tag = $matches[0];

                    $get_attribute = static function (
                        string $name
                    ) use ($tag): string {
                        $pattern =
                            '/\s' .
                            preg_quote($name, '/') .
                            '\s*=\s*(["\'])(.*?)\1/is';

                        if (preg_match($pattern, $tag, $attribute)) {
                            return html_entity_decode(
                                trim($attribute[2]),
                                ENT_QUOTES | ENT_HTML5,
                                'UTF-8'
                            );
                        }

                        return '';
                    };

                    /*
                    * Prefer the lazy-load URL over the placeholder src.
                    */
                    $candidates = [
                        $get_attribute('data-src'),
                        $get_attribute('data-lazy-src'),
                        $get_attribute('data-original'),
                        $get_attribute('src'),
                    ];

                    $image_url = '';

                    foreach ($candidates as $candidate) {
                        $candidate = esc_url_raw($candidate);

                        if (
                            $candidate !== '' &&
                            preg_match('#^https?://#i', $candidate)
                        ) {
                            $image_url = $candidate;
                            break;
                        }
                    }

                    /*
                    * Remove only images with no usable HTTP/HTTPS URL.
                    * This removes transparent data-image placeholders when
                    * no real lazy-load URL is available.
                    */
                    if ($image_url === '') {
                        return '';
                    }

                    $alt = sanitize_text_field(
                        $get_attribute('alt')
                    );

                    $width = absint(
                        $get_attribute('width')
                            ?: $get_attribute('data-width')
                    );

                    $height = absint(
                        $get_attribute('height')
                            ?: $get_attribute('data-height')
                    );

                    $clean_image = sprintf(
                        '<img src="%s" alt="%s" loading="lazy" decoding="async"',
                        esc_url($image_url),
                        esc_attr($alt)
                    );

                    if ($width) {
                        $clean_image .= sprintf(
                            ' width="%d"',
                            $width
                        );
                    }

                    if ($height) {
                        $clean_image .= sprintf(
                            ' height="%d"',
                            $height
                        );
                    }

                    $clean_image .= '>';

                    return $clean_image;
                },
                $desc
            );

            /*
            * Remove figure wrappers left empty after deleting images, while
            * preserving figures containing meaningful captions or text.
            */
            $desc = preg_replace(
                '#<figure\b[^>]*>\s*</figure>#is',
                '',
                $desc
            );

            /*
            * Remove paragraphs that became empty after image removal.
            */
            $desc = preg_replace(
                '#<p\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</p>#is',
                '',
                $desc
            );

            /* -------------------------------------------------
             * 2. Your Existing Cleanup Logic
             * ------------------------------------------------- */

            // Remove FULL-BLOCK italics but keep inline italics intact
            $desc = preg_replace('/<p>\s*<(em|i)>(.*?)<\/\1>\s*<\/p>/is', '<p>$2</p>', $desc);

            // Remove root-level italics (no <p> wrapper)
            $desc = preg_replace('/^<(em|i)>(.*?)<\/\1>$/is', '$2', $desc);

            // Strip links but keep text
            $desc = preg_replace( '/<a\s+[^>]*>(.*?)<\/a>/is', '$1', $desc );

            // Clean <li> formatting
            $desc = preg_replace_callback( '/<li>(.*?)<\/li>/is', function ( $m ) {

                $item = $m[1];

                $item = preg_replace( '/^<b>\s*["\']\s*(<[^>]+>[^<]+<\/[^>]+>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item );
                $item = preg_replace( '/<b>\s*["\']\s*(<em>[^<]+<\/em>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item );
                $item = preg_replace( '/<b>\s*["\']([^<]+)["\']\s*<\/b>/i', '<b>$1</b>', $item );
                $item = preg_replace( '/(<\/(?:em|strong|b)>)["\']/', '$1', $item );
                $item = preg_replace( '/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $item );

                return '<li>' . $item . '</li>';

            }, $desc );

            $desc = preg_replace( '/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $desc );
            $desc = preg_replace( '/^["\']\s*|\s*["\']$/i', '', $desc );


            /* -------------------------------------------------
             * 3. Table Cleanup
             * ------------------------------------------------- */

            $desc = preg_replace_callback( '/<table.*?>.*?<\/table>/is', function ( $m ) {

                $dom = new DOMDocument();
                libxml_use_internal_errors( true );
                $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $m[0] );

                $xpath = new DOMXPath( $dom );
                $header_ths = $xpath->query( '//th' );

                $sidebar_idx = -1;

                foreach ( $header_ths as $i => $th ) {
                    if ( trim( $th->textContent ) === 'Sidebar Location' ) {
                        $sidebar_idx = $i;
                        $th->parentNode->removeChild( $th );
                        break;
                    }
                }

                if ( $sidebar_idx > -1 ) {
                    foreach ( $xpath->query( '//tr' ) as $row ) {
                        $tds = $row->getElementsByTagName( 'td' );
                        if ( $tds->length > $sidebar_idx ) {
                            $row->removeChild( $tds->item( $sidebar_idx ) );
                        }
                    }
                }

                $body = $dom->getElementsByTagName( 'body' )->item( 0 );
                $html = '';

                foreach ( $body->childNodes as $child ) {
                    $html .= $dom->saveHTML( $child );
                }

                return $html;

            }, $desc );


            /* -------------------------------------------------
             * 4. Heading Normalization
             * ------------------------------------------------- */

            $has_h2 = preg_match( '/<h2\b/i', $desc );

            $desc = preg_replace( '/<h6([^>]*)>/i', '<h5$1>', $desc );
            $desc = preg_replace( '/<\/h6>/i', '</h5>', $desc );

            $desc = preg_replace( '/<h5([^>]*)>/i', '<h4$1>', $desc );
            $desc = preg_replace( '/<\/h5>/i', '</h4>', $desc );

            $desc = preg_replace( '/<h4([^>]*)>/i', '<h3$1>', $desc );
            $desc = preg_replace( '/<\/h4>/i', '</h3>', $desc );

            if ( $has_h2 ) {
                $desc = preg_replace( '/<h3([^>]*)>/i', '<h4$1>', $desc );
                $desc = preg_replace( '/<\/h3>/i', '</h4>', $desc );

                $desc = preg_replace( '/<h2([^>]*)>/i', '<h3$1>', $desc );
                $desc = preg_replace( '/<\/h2>/i', '</h3>', $desc );
            }

            $desc = preg_replace( '/<h1([^>]*)>/i', '<h3$1>', $desc );
            $desc = preg_replace( '/<\/h1>/i', '</h3>', $desc );

           /*
            * Keep normal description formatting but remove unsupported,
            * potentially unsafe or API-specific markup.
            */
            $allowed_html = [
                'p' => [],
                'br' => [],
                'strong' => [],
                'b' => [],
                'em' => [],
                'i' => [],
                'ul' => [],
                'ol' => [],
                'li' => [],
                'blockquote' => [],
                'h3' => [],
                'h4' => [],
                'h5' => [],
                'table' => [],
                'thead' => [],
                'tbody' => [],
                'tfoot' => [],
                'tr' => [],
                'th' => [
                    'colspan' => true,
                    'rowspan' => true,
                ],
                'td' => [
                    'colspan' => true,
                    'rowspan' => true,
                ],
                'img' => [
                    'src' => true,
                    'alt' => true,
                    'width' => true,
                    'height' => true,
                    'loading' => true,
                    'decoding' => true,
                ],
            ];

            $desc = wp_kses($desc, $allowed_html);

            return trim($desc);

        }

}
