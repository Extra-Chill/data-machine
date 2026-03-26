<?php
/**
 * REST API Flows Endpoint
 *
 * Provides REST API access to flow CRUD operations.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

use DataMachine\Abilities\PermissionHelper;
use WP_REST_Server;
use DataMachine\Api\Traits\HasRegister;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Flows {

}
