<?php
/**
 * Agent Memory Service
 *
 * Provides structured read/write operations for agent memory files (MEMORY.md).
 * Parses markdown sections and supports section-level operations.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.30.0
 */

namespace DataMachine\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

class AgentMemory {

	/**
	 * @var DirectoryManager
	 */
	private DirectoryManager $directory_manager;

	/**
	 * @var string
	 */
	private string $file_path;

	public function __construct() {
		$this->directory_manager = new DirectoryManager();
		$agent_dir               = $this->directory_manager->get_agent_directory();
		$this->file_path         = "{$agent_dir}/MEMORY.md";
	}

	/**
	 * Get the full path to MEMORY.md.
	 *
	 * @return string
	 */
	public function get_file_path(): string {
		return $this->file_path;
	}

	/**
	 * Read the full memory file content.
	 *
	 * @return array{success: bool, content?: string, message?: string}
	 */
	public function get_all(): array {
		if ( ! file_exists( $this->file_path ) ) {
			return array(
				'success' => false,
				'message' => 'Memory file does not exist.',
			);
		}

		$content = file_get_contents( $this->file_path );

		return array(
			'success' => true,
			'content' => $content,
		);
	}

	/**
	 * List all section headers in the memory file.
	 *
	 * Sections are defined by markdown ## headers.
	 *
	 * @return array{success: bool, sections?: string[], message?: string}
	 */
	public function get_sections(): array {
		if ( ! file_exists( $this->file_path ) ) {
			return array(
				'success' => false,
				'message' => 'Memory file does not exist.',
			);
		}

		$content  = file_get_contents( $this->file_path );
		$sections = $this->parse_section_headers( $content );

		return array(
			'success'  => true,
			'sections' => $sections,
		);
	}

	/**
	 * Get the content of a specific section.
	 *
	 * @param string $section_name Section header text (without ##).
	 * @return array{success: bool, section?: string, content?: string, message?: string}
	 */
	public function get_section( string $section_name ): array {
		if ( ! file_exists( $this->file_path ) ) {
			return array(
				'success' => false,
				'message' => 'Memory file does not exist.',
			);
		}

		$content = file_get_contents( $this->file_path );
		$parsed  = $this->parse_section( $content, $section_name );

		if ( null === $parsed ) {
			$sections = $this->parse_section_headers( $content );
			return array(
				'success'            => false,
				'message'            => sprintf( 'Section "%s" not found.', $section_name ),
				'available_sections' => $sections,
			);
		}

		return array(
			'success' => true,
			'section' => $section_name,
			'content' => $parsed,
		);
	}

	/**
	 * Set (replace) the content of a specific section.
	 *
	 * Creates the section if it doesn't exist.
	 *
	 * @param string $section_name Section header text (without ##).
	 * @param string $content New content for the section.
	 * @return array{success: bool, message: string}
	 */
	public function set_section( string $section_name, string $content ): array {
		$this->ensure_file_exists();

		$file_content = file_get_contents( $this->file_path );
		$section_pos  = $this->find_section_position( $file_content, $section_name );

		if ( null === $section_pos ) {
			// Append new section at end of file.
			$new_section   = "\n## {$section_name}\n{$content}\n";
			$file_content .= $new_section;
		} else {
			// Replace existing section content.
			$file_content = $this->replace_section_content( $file_content, $section_pos, $content );
		}

		$this->write_file( $file_content );

		return array(
			'success' => true,
			'message' => sprintf( 'Section "%s" updated.', $section_name ),
		);
	}

	/**
	 * Append content to a specific section.
	 *
	 * Creates the section if it doesn't exist.
	 *
	 * @param string $section_name Section header text (without ##).
	 * @param string $content Content to append.
	 * @return array{success: bool, message: string}
	 */
	public function append_to_section( string $section_name, string $content ): array {
		$this->ensure_file_exists();

		$file_content = file_get_contents( $this->file_path );
		$section_pos  = $this->find_section_position( $file_content, $section_name );

		if ( null === $section_pos ) {
			// Create section with the content.
			$new_section   = "\n## {$section_name}\n{$content}\n";
			$file_content .= $new_section;
		} else {
			// Append to existing section.
			$existing     = $this->parse_section( $file_content, $section_name );
			$merged       = rtrim( $existing ) . "\n" . $content . "\n";
			$file_content = $this->replace_section_content( $file_content, $section_pos, $merged );
		}

		$this->write_file( $file_content );

		return array(
			'success' => true,
			'message' => sprintf( 'Content appended to section "%s".', $section_name ),
		);
	}

	// =========================================================================
	// Internal Parsing
	// =========================================================================

	/**
	 * Parse all ## section headers from markdown content.
	 *
	 * @param string $content Markdown content.
	 * @return string[] List of section names.
	 */
	private function parse_section_headers( string $content ): array {
		$sections = array();
		if ( preg_match_all( '/^## (.+)$/m', $content, $matches ) ) {
			$sections = array_map( 'trim', $matches[1] );
		}
		return $sections;
	}

	/**
	 * Extract the body content of a named section.
	 *
	 * @param string $content Full file content.
	 * @param string $section_name Section header to find.
	 * @return string|null Section body or null if not found.
	 */
	private function parse_section( string $content, string $section_name ): ?string {
		$escaped = preg_quote( $section_name, '/' );
		$pattern = '/^## ' . $escaped . '\s*\n(.*?)(?=\n## |\z)/ms';

		if ( preg_match( $pattern, $content, $match ) ) {
			return trim( $match[1] );
		}

		return null;
	}

	/**
	 * Find the position of a section header in the file content.
	 *
	 * @param string $content File content.
	 * @param string $section_name Section header to find.
	 * @return array{start: int, header_end: int, end: int}|null Position data or null.
	 */
	private function find_section_position( string $content, string $section_name ): ?array {
		$escaped = preg_quote( $section_name, '/' );
		$pattern = '/^(## ' . $escaped . '\s*\n)(.*?)(?=\n## |\z)/ms';

		if ( preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE ) ) {
			$full_start  = $match[0][1];
			$header      = $match[1][0];
			$header_end  = $full_start + strlen( $header );
			$body        = $match[2][0];
			$section_end = $header_end + strlen( $body );

			return array(
				'start'      => $full_start,
				'header_end' => $header_end,
				'end'        => $section_end,
			);
		}

		return null;
	}

	/**
	 * Replace the body content of a section at the given position.
	 *
	 * @param string $file_content Full file content.
	 * @param array  $position Position data from find_section_position.
	 * @param string $new_content New body content.
	 * @return string Updated file content.
	 */
	private function replace_section_content( string $file_content, array $position, string $new_content ): string {
		$before = substr( $file_content, 0, $position['header_end'] );
		$after  = substr( $file_content, $position['end'] );

		return $before . $new_content . "\n" . $after;
	}

	/**
	 * Ensure the memory file and directory exist.
	 */
	private function ensure_file_exists(): void {
		$agent_dir = $this->directory_manager->get_agent_directory();
		$this->directory_manager->ensure_directory_exists( $agent_dir );

		if ( ! file_exists( $this->file_path ) ) {
			file_put_contents( $this->file_path, "# Agent Memory\n" );
		}
	}

	/**
	 * Write content to the memory file.
	 *
	 * @param string $content File content.
	 */
	private function write_file( string $content ): void {
		file_put_contents( $this->file_path, $content );
	}
}
