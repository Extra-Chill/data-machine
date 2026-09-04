<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Compression;

/**
 * Explicit operator-provided zstd command adapter.
 */
final class ZstdCommandDecoder
{
    /**
     * @param array<int, string> $command Command argv, not a shell string.
     */
    public function __construct(private readonly array $command)
    {
    }

    /**
     * @param array<string, mixed> $context
     * @return array{data: string|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function __invoke(string $payload, array $context): array
    {
        if ( empty($this->command) || '' === (string) $this->command[0] ) {
            return array(
                'data'        => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_zstd_command_missing', 'Configured Zstandard command is empty.', $context)),
            );
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'blocks-engine-zstd-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'blocks-engine-zstd-out-');
        if ( false === $inputPath || false === $outputPath ) {
            if ( false !== $inputPath ) {
                @unlink($inputPath);
            }
            if ( false !== $outputPath ) {
                @unlink($outputPath);
            }

            return array(
                'data'        => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_zstd_command_tempfile_failed', 'Temporary files could not be created for Zstandard command decoding.', $context)),
            );
        }

        file_put_contents($inputPath, $payload);

        $descriptors = array(
            0 => array('file', $inputPath, 'r'),
            1 => array('file', $outputPath, 'w'),
            2 => array('pipe', 'w'),
        );

        $process = @proc_open($this->command, $descriptors, $pipes);
        if ( ! is_resource($process) ) {
            @unlink($inputPath);
            @unlink($outputPath);

            return array(
                'data'        => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_zstd_command_open_failed', 'Configured Zstandard command could not be started.', $context)),
            );
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $decodedBytes = 0 === $exitCode && is_readable($outputPath) ? filesize($outputPath) : false;
        $maxDecodedBytes = isset($context['max_decoded_bytes']) && is_numeric($context['max_decoded_bytes']) ? max(0, (int) $context['max_decoded_bytes']) : 0;
        if ( 0 === $exitCode && false !== $decodedBytes && $maxDecodedBytes > 0 && (int) $decodedBytes > $maxDecodedBytes ) {
            @unlink($inputPath);
            @unlink($outputPath);

            return array(
                'data'        => null,
                'diagnostics' => array(
                    $this->diagnostic(
                        'figma_transformer_zstd_command_output_preflight_failed',
                        'Configured Zstandard command output exceeds the safe read limit and was not loaded into memory.',
                        array_merge(
                            $context,
                            array(
                                'decoded_bytes'     => (int) $decodedBytes,
                                'max_decoded_bytes' => $maxDecodedBytes,
                            )
                        )
                    ),
                ),
            );
        }

        $decoded = 0 === $exitCode && is_readable($outputPath) ? file_get_contents($outputPath) : false;
        @unlink($inputPath);
        @unlink($outputPath);
        if ( 0 !== $exitCode || ! is_string($decoded) ) {
            return array(
                'data'        => null,
                'diagnostics' => array(
                    $this->diagnostic(
                        'figma_transformer_zstd_command_failed',
                        'Configured Zstandard command failed to decode the payload.',
                        array_merge(
                            $context,
                            array(
                                'exit_code'      => $exitCode,
                                'stderr_preview' => is_string($stderr) ? substr($stderr, 0, 200) : '',
                            )
                        )
                    ),
                ),
            );
        }

        return array(
            'data'        => $decoded,
            'diagnostics' => array(
                $this->diagnostic('figma_transformer_zstd_command_used', 'Zstandard chunk decoded by a configured command adapter.', $context),
            ),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $message, array $context): array
    {
        return array(
            'code'    => $code,
            'message' => $message,
            'source'  => 'ZstdCommandDecoder',
            'context' => array_merge(
                $context,
                array(
                    'command' => $this->redactedCommand(),
                )
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private function redactedCommand(): array
    {
        return array_map(
            static fn (string $part): string => preg_match('/(token|secret|password|key)=/i', $part) ? '[redacted]' : $part,
            $this->command
        );
    }
}
