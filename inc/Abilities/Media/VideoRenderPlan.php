<?php
/**
 * Pure ffmpeg argument planning for video rendering.
 *
 * @package DataMachine\Abilities\Media
 */

namespace DataMachine\Abilities\Media;

defined( 'ABSPATH' ) || exit;

final class VideoRenderPlan {
	/** Build the ordered ffmpeg arguments and caption subtitle content. */
	public static function build( array $spec, array $sources ): array {
		$segments = $spec['segments'] ?? array();
		if ( ! is_array( $segments ) || count( $segments ) < 1 || count( $segments ) > 20 ) {
			throw new \InvalidArgumentException( 'segments must contain between 1 and 20 items.' );
		}
		$output = $spec['output'] ?? array();
		$width  = (int) ( $output['width'] ?? 1080 );
		$height = (int) ( $output['height'] ?? 1920 );
		$fps    = (int) ( $output['fps'] ?? 30 );
		if ( $width < 2 || $height < 2 || $fps < 1 ) {
			throw new \InvalidArgumentException( 'Output dimensions and fps must be positive.' );
		}
		$args   = array( '-y' );
		$filters = array();
		$total_ms = 0;
		foreach ( $segments as $index => $segment ) {
			$path = $sources[ $index ] ?? '';
			if ( ! is_string( $path ) || '' === $path ) {
				throw new \InvalidArgumentException( 'Every segment requires a resolved source path.' );
			}
			$kind = $segment['kind'] ?? '';
			if ( ! in_array( $kind, array( 'video', 'image' ), true ) ) {
				throw new \InvalidArgumentException( 'Segment kind must be video or image.' );
			}
			$duration_ms = 'image' === $kind ? (int) ( $segment['duration_ms'] ?? 0 ) : (int) ( ( $segment['out_ms'] ?? 0 ) - ( $segment['in_ms'] ?? 0 ) );
			if ( $duration_ms < 1 ) {
				throw new \InvalidArgumentException( 'Each segment must have a positive duration.' );
			}
			$total_ms += $duration_ms;
			if ( $total_ms > 90000 ) {
				throw new \InvalidArgumentException( 'Total video duration cannot exceed 90 seconds.' );
			}
			if ( 'image' === $kind ) {
				$args = array_merge( $args, array( '-loop', '1', '-t', sprintf( '%.3f', $duration_ms / 1000 ), '-i', $path ) );
			} else {
				$args = array_merge( $args, array( '-ss', sprintf( '%.3f', (int) ( $segment['in_ms'] ?? 0 ) / 1000 ), '-t', sprintf( '%.3f', $duration_ms / 1000 ), '-i', $path ) );
			}
			$motion = $segment['motion'] ?? 'none';
			$zoom = 'zoom_in' === $motion ? 'min(zoom+0.0015,1.5)' : ( 'zoom_out' === $motion ? 'if(eq(on,1),1.5,max(zoom-0.0015,1))' : '1' );
			$filter = sprintf( '[%d:v]scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1,fps=%d', $index, $width, $height, $width, $height, $fps );
			if ( 'none' !== $motion ) {
				$filter .= sprintf( ',zoompan=z=\'%s\':d=1:s=%dx%d:fps=%d', $zoom, $width, $height, $fps );
			}
			$filters[] = $filter . sprintf( '[v%d]', $index );
		}
		$concat = '';
		foreach ( array_keys( $filters ) as $index ) {
			$concat .= '[v' . $index . ']';
		}
		$filter = implode( ';', $filters ) . ';' . $concat . 'concat=n=' . count( $filters ) . ':v=1:a=0,format=yuv420p[outv]';
		if ( ! empty( $spec['audio'] ) ) {
			$audio = $sources['audio'] ?? '';
			if ( '' === $audio ) {
				throw new \InvalidArgumentException( 'Audio requires a resolved source path.' );
			}
			$args[] = '-i';
			$args[] = $audio;
			$filter .= ';[' . count( $segments ) . ':a]atrim=duration=' . sprintf( '%.3f', $total_ms / 1000 ) . ',afade=t=out:st=' . sprintf( '%.3f', max( 0, ( $total_ms - 1000 ) / 1000 ) ) . ':d=' . sprintf( '%.3f', min( 1, $total_ms / 1000 ) ) . '[outa]';
		}
		$args = array_merge( $args, array( '-filter_complex', $filter, '-map', '[outv]' ) );
		if ( ! empty( $spec['audio'] ) ) {
			$args = array_merge( $args, array( '-map', '[outa]', '-c:a', 'aac' ) );
		}
		$args = array_merge( $args, array( '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-r', (string) $fps ) );
		return array( 'arguments' => $args, 'duration_ms' => $total_ms, 'width' => $width, 'height' => $height, 'captions_ass' => self::captions_ass( $spec['captions'] ?? array(), $width, $height ) );
	}

	private static function captions_ass( array $captions, int $width, int $height ): string {
		$ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, BackColour, Bold, Italic, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\nStyle: Default,Arial,48,&H00FFFFFF,&H80000000,0,0,3,1,0,2,60,60,60,1\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
		foreach ( $captions as $caption ) {
			$position = array( 'bottom' => 2, 'top' => 8, 'center' => 5 )[ $caption['position'] ?? 'bottom' ] ?? 2;
			$start = max( 0, (int) ( $caption['start_ms'] ?? 0 ) );
			$end = max( $start, (int) ( $caption['end_ms'] ?? $start ) );
			$text = str_replace( array( "\\", '{', '}' ), array( '\\\\', '\\{', '\\}' ), (string) ( $caption['text'] ?? '' ) );
			$ass .= sprintf( "Dialogue: 0,%s,%s,Default,,0,0,0,,{\\an%d}%s\n", self::ass_time( $start ), self::ass_time( $end ), $position, $text );
		}
		return $ass;
	}

	private static function ass_time( int $milliseconds ): string {
		return sprintf( '%d:%02d:%02d.%02d', intdiv( $milliseconds, 3600000 ), intdiv( $milliseconds % 3600000, 60000 ), intdiv( $milliseconds % 60000, 1000 ), intdiv( $milliseconds % 1000, 10 ) );
	}
}
