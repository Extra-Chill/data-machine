<?php
/**
 * Auth ref resolver interface.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

interface AuthRefResolverInterface {


	/**
	 * Resolve an auth ref to local non-secret auth metadata/config handles.
	 *
	 * Implementations must not return raw secret values.
	 *
	 * @param  AuthRef $ref     Symbolic auth reference.
	 * @param  array   $context Import/export context.
	 * @return array{resolved:bool, ref:string, provider:string, account:string, config?:array, warning?:string}
	 */
	public function resolve( AuthRef $ref, array $context = array() ): array;
}
