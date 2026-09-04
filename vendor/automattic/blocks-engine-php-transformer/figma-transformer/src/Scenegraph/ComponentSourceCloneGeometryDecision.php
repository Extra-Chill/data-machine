<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Explains which box wins when component-source clone geometry is refreshed.
 */
final class ComponentSourceCloneGeometryDecision
{
    public const REASON_REFRESHED_BOX_NOT_PARENT_LOCAL = 'refreshed-box-not-parent-local';
    public const REASON_CLONE_NOT_COMPONENT_SOURCE = 'clone-not-component-source';
    public const REASON_CLONE_BOX_X_FAR_FROM_REFRESHED = 'clone-box-x-far-from-refreshed';
    public const REASON_CLONE_BOX_Y_FAR_FROM_REFRESHED = 'clone-box-y-far-from-refreshed';
    public const REASON_CLONE_BOX_X_DISAGREES_WITH_SCALAR = 'clone-box-x-disagrees-with-scalar';
    public const REASON_CLONE_BOX_Y_DISAGREES_WITH_SCALAR = 'clone-box-y-disagrees-with-scalar';
    public const REASON_CLONE_X_FAR_FROM_REFRESHED = 'clone-x-far-from-refreshed';
    public const REASON_CLONE_Y_FAR_FROM_REFRESHED = 'clone-y-far-from-refreshed';
    public const REASON_CLONE_GEOMETRY_PRESERVED = 'clone-geometry-preserved';

    public function __construct(
        public readonly bool $useRefreshedGeometry,
        public readonly string $reason,
        public readonly ?string $dimension = null,
        public readonly ?string $refreshedCoordinateSpace = null,
        public readonly bool $hasComponentSourceIdentity = false
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'use_refreshed_geometry'       => $this->useRefreshedGeometry,
            'reason'                       => $this->reason,
            'dimension'                    => $this->dimension,
            'refreshed_coordinate_space'   => $this->refreshedCoordinateSpace,
            'has_component_source_identity' => $this->hasComponentSourceIdentity,
        );
    }
}
