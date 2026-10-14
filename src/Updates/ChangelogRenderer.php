<?php
/**
 * Changelog Markdown-to-HTML renderer for the update "View details" modal.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Renders a safe Markdown subset for the update modal.
 *
 * Unsupported Markdown is escaped as text. HTML passes through esc_url() and
 * wp_kses_post().
 *
 * @since 1.0.0
 */
final class ChangelogRenderer {

	/**
	 * Object-cache group for rendered changelog HTML.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint';

	/**
	 * Prefix for the object-cache keys under {@see self::CACHE_GROUP}.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'changelog_';

	/**
	 * Placeholder tokens for the current inline pass.
	 *
	 * @var array<string, string>
	 */
	private array $inlineStore = [];

	/**
	 * Counter for unique inline placeholder tokens.
	 *
	 * @var int
	 */
	private int $inlineCounter = 0;

	/**
	 * Render changelog Markdown to sanitized HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markdown The authored Markdown changelog source.
	 * @return string Sanitized HTML for `sections.changelog`, or '' when the
	 *                input has no renderable content.
	 */
	public function render( string $markdown ): string {
		// Reject placeholder delimiters from input.
		$markdown = str_replace( [ "\x00", "\x02", "\x03" ], '', $markdown );

		// Use one line ending on every platform.
		$markdown = str_replace( [ "\r\n", "\r" ], "\n", $markdown );

		// Empty input has no output.
		if ( '' === trim( $markdown ) ) {
			return '';
		}

		$html = $this->parseBlocks( explode( "\n", $markdown ) );

		if ( '' === $html ) {
			return '';
		}

		// Keep a second HTML safety check.
		return wp_kses_post( $html );
	}

	/**
	 * Render changelog Markdown to HTML, caching the result in the object cache.
	 *
	 * The content hash invalidates the cache after an edit.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $releaseId The release the changelog belongs to.
	 * @param string $markdown  The authored Markdown changelog source.
	 * @return string Sanitized HTML for `sections.changelog`.
	 */
	public function renderCached( int $releaseId, string $markdown ): string {
		// A content change creates a new cache key.
		$key = self::CACHE_PREFIX . $releaseId . '_' . md5( $markdown );

		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$html = $this->render( $markdown );
		wp_cache_set( $key, $html, self::CACHE_GROUP );

		return $html;
	}

	/**
	 * Parse the document's lines into concatenated block-level HTML.
	 *
	 * Each handler advances the shared line cursor.
	 *
	 * @param array<int, string> $lines The document split on newlines.
	 * @return string The concatenated block HTML.
	 */
	private function parseBlocks( array $lines ): string {
		$out   = '';
		$index = 0;
		$count = count( $lines );

		while ( $index < $count ) {
			$line = $lines[ $index ];

			// Blank lines separate blocks.
			if ( '' === trim( $line ) ) {
				++$index;
				continue;
			}

			// A fence starts a code block.
			if ( 1 === preg_match( '/^ {0,3}`{3,}/', $line ) ) {
				$out .= $this->consumeFencedCode( $lines, $index, $count );
				continue;
			}

			// An ATX heading has one to six hashes and a space.
			if ( 1 === preg_match( '/^ {0,3}(#{1,6})\s+(.*)$/', $line, $matches ) ) {
				$level = strlen( $matches[1] );
				// Trim optional closing hashes.
				$text = (string) preg_replace( '/\s+#+\s*$/', '', $matches[2] );
				$out .= '<h' . $level . '>' . $this->renderInline( $text ) . '</h' . $level . '>';
				++$index;
				continue;
			}

			// A run of `- ` or `* ` lines forms an unordered list.
			if ( 1 === preg_match( '/^ {0,3}[-*]\s+/', $line ) ) {
				$out .= $this->consumeList( $lines, $index, $count, '/^ {0,3}[-*]\s+(.*)$/', 'ul' );
				continue;
			}

			// A run of numbered lines forms an ordered list.
			if ( 1 === preg_match( '/^ {0,3}\d+\.\s+/', $line ) ) {
				$out .= $this->consumeList( $lines, $index, $count, '/^ {0,3}\d+\.\s+(.*)$/', 'ol' );
				continue;
			}

			// Other text forms a paragraph.
			$out .= $this->consumeParagraph( $lines, $index, $count );
		}

		return $out;
	}

	/**
	 * Consume a fenced code block starting at the opening fence.
	 *
	 * @param array<int, string> $lines The document lines.
	 * @param int                $index Cursor at the opening fence; advanced past
	 *                                  the block (and its closing fence, if any).
	 * @param int                $count Total line count.
	 * @return string The `<pre><code>` HTML with escaped contents.
	 */
	private function consumeFencedCode( array $lines, int &$index, int $count ): string {
		// Skip the opening fence.
		++$index;

		$code = [];
		while ( $index < $count && 1 !== preg_match( '/^ {0,3}`{3,}\s*$/', $lines[ $index ] ) ) {
			$code[] = $lines[ $index ];
			++$index;
		}

		// Skip the closing fence when present.
		if ( $index < $count ) {
			++$index;
		}

		return '<pre><code>' . $this->escape( implode( "\n", $code ) ) . '</code></pre>';
	}

	/**
	 * Consume a run of list-item lines into a single list element.
	 *
	 * @param array<int, string> $lines   The document lines.
	 * @param int                $index   Cursor at the first item; advanced past
	 *                                     the last consumed item.
	 * @param int                $count   Total line count.
	 * @param string             $pattern Item regex whose first group is the item text.
	 * @param string             $tag     The list element tag, 'ul' or 'ol'.
	 * @return string The list HTML.
	 */
	private function consumeList( array $lines, int &$index, int $count, string $pattern, string $tag ): string {
		$items = '';

		while ( $index < $count && 1 === preg_match( $pattern, $lines[ $index ], $matches ) ) {
			$items .= '<li>' . $this->renderInline( $matches[1] ) . '</li>';
			++$index;
		}

		return '<' . $tag . '>' . $items . '</' . $tag . '>';
	}

	/**
	 * Consume a run of lines into one paragraph.
	 *
	 * Newlines within the paragraph become spaces.
	 *
	 * @param array<int, string> $lines The document lines.
	 * @param int                $index Cursor at the paragraph's first line;
	 *                                   advanced past the paragraph.
	 * @param int                $count Total line count.
	 * @return string The `<p>` HTML.
	 */
	private function consumeParagraph( array $lines, int &$index, int $count ): string {
		$parts = [];

		while ( $index < $count ) {
			$line = $lines[ $index ];

			if ( '' === trim( $line ) || $this->opensBlock( $line ) ) {
				break;
			}

			// Joining trimmed lines preserves one space.
			$parts[] = trim( $line );
			++$index;
		}

		return '<p>' . $this->renderInline( implode( ' ', $parts ) ) . '</p>';
	}

	/**
	 * Whether a line starts another block.
	 *
	 * @param string $line The line to test.
	 * @return bool True for a heading, fence, or list-item line.
	 */
	private function opensBlock( string $line ): bool {
		return 1 === preg_match( '/^ {0,3}`{3,}/', $line )
			|| 1 === preg_match( '/^ {0,3}#{1,6}\s+/', $line )
			|| 1 === preg_match( '/^ {0,3}[-*]\s+/', $line )
			|| 1 === preg_match( '/^ {0,3}\d+\.\s+/', $line );
	}

	/**
	 * Render one run of inline text (heading, list item, or paragraph body).
	 *
	 * Code spans and valid links skip later parsing passes.
	 *
	 * @param string $text The raw inline markdown.
	 * @return string The sanitized inline HTML.
	 */
	private function renderInline( string $text ): string {
		// Reset this pass's placeholders.
		$this->inlineStore   = [];
		$this->inlineCounter = 0;

		// Code spans must not be parsed again.
		$text = (string) preg_replace_callback( '/`([^`]+)`/', [ $this, 'stashCodeSpan' ], $text );

		// Links skip emphasis parsing.
		$text = (string) preg_replace_callback( '/\[([^\]]*)\]\(([^)\s]*)\)/', [ $this, 'stashLink' ], $text );

		// Escape remaining text and rejected links.
		$text = $this->escape( $text );

		// Placeholder tokens cannot match emphasis patterns.
		$text = $this->emphasize( $text );

		// Restore nested placeholders until none remain.
		$previous = '';
		while ( $previous !== $text ) {
			$previous = $text;
			$text     = strtr( $text, $this->inlineStore );
		}

		return $text;
	}

	/**
	 * Replace a matched code span with a placeholder token.
	 *
	 * @param array<int, string> $matches preg match: [0] the whole span, [1] the
	 *                                    code contents.
	 * @return string The placeholder token standing in for the code element.
	 */
	private function stashCodeSpan( array $matches ): string {
		$token = $this->nextToken();
		// Escape code contents before storing them.
		$this->inlineStore[ $token ] = '<code>' . $this->escape( $matches[1] ) . '</code>';

		return $token;
	}

	/**
	 * Replace a safe Markdown link with a placeholder anchor.
	 *
	 * @param array<int, string> $matches preg match: [0] the whole link, [1] the
	 *                                    link text, [2] the URL.
	 * @return string The placeholder token for a safe link, or the original
	 *                literal markdown for an unsafe one (escaped downstream).
	 */
	private function stashLink( array $matches ): string {
		$url = $matches[2];

		// Only absolute HTTP URLs become links.
		if ( 1 !== preg_match( '#^https?://#i', $url ) ) {
			return $matches[0];
		}

		// Encode the URL for an HTML attribute.
		$href = esc_url( $url );
		// Parse link text like normal text.
		$inner = $this->emphasize( $this->escape( $matches[1] ) );

		$token                       = $this->nextToken();
		$this->inlineStore[ $token ] = '<a href="' . $href . '" rel="noopener noreferrer">' . $inner . '</a>';

		return $token;
	}

	/**
	 * Produce the next unique placeholder token for the current inline pass.
	 *
	 * Input cannot contain its control-character delimiters.
	 *
	 * @return string A control-character-delimited placeholder token.
	 */
	private function nextToken(): string {
		return "\x02" . ( $this->inlineCounter++ ) . "\x03";
	}

	/**
	 * Apply bold and italic emphasis to already-escaped text.
	 *
	 * Bold runs before italic. Word underscores stay unchanged.
	 *
	 * @param string $text Already HTML-escaped text.
	 * @return string The text with emphasis tags inserted.
	 */
	private function emphasize( string $text ): string {
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $text );
		$text = (string) preg_replace( '/(?<![\p{L}\p{N}_])_(.+?)_(?![\p{L}\p{N}_])/us', '<em>$1</em>', $text );

		return $text;
	}

	/**
	 * Escape a run of text so no HTML markup in it survives.
	 *
	 * @param string $text The raw text.
	 * @return string The text with &, <, >, ", and ' encoded.
	 */
	private function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
