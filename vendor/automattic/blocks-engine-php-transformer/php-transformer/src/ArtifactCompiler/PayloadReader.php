<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/**
 * Consumer-provided payload transport for staged artifact plans.
 *
 * References are plain serializable data; implementations may read them from
 * any backing store without making the compiler aware of that store.
 */
interface PayloadReader
{
    /** @param array{schema:string,id:string,bytes:int,sha256:string} $reference */
    public function read(array $reference): string;
}
