<?php
/**
 * GitHub Updater
 *
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 * @package   github-updater
 */

namespace Fragen\GitHub_Updater\Traits;

use Fragen\Singleton;
use Fragen\GitHub_Updater\Readme_Parser as Readme_Parser;

/**
 * Trait API_Common
 */
trait API_Common {
	/**
	 * Holds loose class method name.
	 *
	 * @var null
	 */
	protected static $method;

	/**
	 * Decode API responses that are base64 encoded.
	 *
	 * @param  string $git      (github|bitbucket|gitlab|gitea).
	 * @param  mixed  $response API response.
	 * @return mixed  $response
	 */
	private function decode_response( $git, $response ) {
		switch ( $git ) {
			case 'github':
			case 'gitlab':
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$response = isset( $response->content ) ? base64_decode( $response->content ) : $response;
				break;
			case 'bbserver':
				$response = isset( $response->lines ) ? $this->bbserver_recombine_response( $response ) : $response;
				break;
		}

		return $response;
	}

	/**
	 * Parse API response that returns as stdClass.
	 *
	 * @param  string $git      (github|bitbucket|gitlab|gitea).
	 * @param  mixed  $response API response.
	 * @return mixed  $response
	 */
	private function parse_response( $git, $response ) {
		switch ( $git ) {
			case 'bitbucket':
			case 'bbserver':
				$response = isset( $response->values ) ? $response->values : $response;
				break;
		}

		return $response;
	}

	/**
	 * Parse API response to release asset URI.
	 *
	 * @param  string $git      (github|bitbucket|gitlab|gitea).
	 * @param  string $request  Query to API->api().
	 * @param  mixed  $response API response.
	 * @return string $response Release asset download link.
	 */
	private function parse_release_asset( $git, $request, $response ) {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		switch ( $git ) {
			case 'github':
				$assets = isset( $response->assets ) ? $response->assets : [];
				foreach ( $assets as $asset ) {
					if ( 1 === count( $assets ) || 0 === strpos( $asset->name, $this->type->slug ) ) {
						$response = $asset->url;
						break;
					}
				}
				$response = is_string( $response ) ? $response : null;
				break;
			case 'bitbucket':
				$download_base = trailingslashit( $this->get_api_url( $request, true ) );
				$assets        = isset( $response->values ) ? $response->values : [];
				foreach ( $assets as $asset ) {
					if ( 1 === count( $assets ) || 0 === strpos( $asset->name, $this->type->slug ) ) {
						$response = $download_base . $asset->name;
						break;
					}
				}
				$response = is_string( $response ) ? $response : null;
				break;
			case 'bbserver':
				// TODO: make work.
				break;
			case 'gitlab':
				$response = $this->get_api_url( $request );
				break;
			case 'gitea':
				break;
		}

		return $response;
	}

	/**
	 * Read the remote file and parse headers.
	 *
	 * @param string $git     github|bitbucket|gitlab|gitea).
	 * @param string $file    Filename.
	 * @param string $request API request.
	 *
	 * @return bool
	 */
	public function get_remote_api_info( $git, $file, $request ) {
		$response = isset( $this->response[ $this->type->slug ] ) ? $this->response[ $this->type->slug ] : false;

		if ( ! $response ) {
			self::$method = 'file';
			$response     = $this->api( $request );
			$response     = $this->decode_response( $git, $response );
		}

		if ( $response && is_string( $response ) && ! is_wp_error( $response ) ) {
			$response = $this->get_file_headers( $response, $this->type->type );
			$this->set_repo_cache( $this->type->slug, $response );
			$this->set_repo_cache( 'repo', $this->type->slug );
		}

		if ( ! is_array( $response ) || $this->validate_response( $response ) ) {
			return false;
		}

		$response['dot_org'] = $this->get_dot_org_data();
		$this->set_file_info( $response );

		return true;
	}

	/**
	 * Get remote info for tags.
	 *
	 * @param string $git     github|bitbucket|gitlab|gitea).
	 * @param string $request API request.
	 *
	 * @return bool
	 */
	public function get_remote_api_tag( $git, $request ) {
		$repo_type = $this->return_repo_type();
		$response  = isset( $this->response['tags'] ) ? $this->response['tags'] : false;

		if ( ! $response ) {
			self::$method = 'tags';
			$response     = $this->api( $request );

			if ( ! $response ) {
				$response          = new \stdClass();
				$response->message = 'No tags found';
			}

			if ( $response ) {
				$response = $this->parse_tag_response( $response );
				$this->set_repo_cache( 'tags', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$tags = $this->parse_tags( $response, $repo_type );
		$this->sort_tags( $tags );

		return true;
	}

	/**
	 * Read the remote CHANGES.md file.
	 *
	 * @param string $git     github|bitbucket|gitlab|gitea).
	 * @param string $changes Changelog filename.
	 * @param string $request API request.
	 *
	 * @return bool
	 */
	public function get_remote_api_changes( $git, $changes, $request ) {
		$response = isset( $this->response['changes'] ) ? $this->response['changes'] : false;

		// Set $response from local file if no update available.
		if ( ! $response && ! $this->can_update_repo( $this->type ) ) {
			$response = $this->get_local_info( $this->type, $changes );
		}

		if ( ! $response ) {
			self::$method = 'changes';
			$response     = $this->api( $request );
			$response     = $this->decode_response( $git, $response );
		}

		if ( ! $response && ! is_wp_error( $response ) ) {
			$response          = new \stdClass();
			$response->message = 'No changelog found';
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		if ( $response && ! isset( $this->response['changes'] ) ) {
			$parser   = new \Parsedown();
			$response = $parser->text( $response );
			$this->set_repo_cache( 'changes', $response );
		}

		$this->type->sections['changelog'] = $response;

		return true;
	}

	/**
	 * Read and parse remote readme.txt.
	 *
	 * @param string $git     github|bitbucket|gitlab|gitea).
	 * @param string $request API request.
	 *
	 * @return bool
	 */
	public function get_remote_api_readme( $git, $request ) {
		if ( ! $this->local_file_exists( 'readme.txt' ) ) {
			return false;
		}

		$response = isset( $this->response['readme'] ) ? $this->response['readme'] : false;

		// Set $response from local file if no update available.
		if ( ! $response && ! $this->can_update_repo( $this->type ) ) {
			$response = $this->get_local_info( $this->type, 'readme.txt' );
		}

		if ( ! $response ) {
			self::$method = 'readme';
			$response     = $this->api( $request );
			$response     = $this->decode_response( $git, $response );
		}

		if ( ! $response && ! is_wp_error( $response ) ) {
			$response          = new \stdClass();
			$response->message = 'No readme found';
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		if ( $response && ! isset( $this->response['readme'] ) ) {
			$parser   = new Readme_Parser( $response );
			$response = $parser->parse_data();
			$this->set_repo_cache( 'readme', $response );
		}

		$this->set_readme_info( $response );

		return true;
	}

	/**
	 * Read the repository meta from API.
	 *
	 * @param string $git     github|bitbucket|gitlab|gitea).
	 * @param string $request API request.
	 *
	 * @return bool
	 */
	public function get_remote_api_repo_meta( $git, $request ) {
		$response = isset( $this->response['meta'] ) ? $this->response['meta'] : false;

		if ( ! $response ) {
			self::$method = 'meta';
			$response     = $this->api( $request );

			if ( $response ) {
				$response = $this->parse_meta_response( $response );
				$this->set_repo_cache( 'meta', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->repo_meta = $response;
		$this->add_meta_repo_object();

		return true;
	}

	/**
	 * Create array of branches and download links as array.
	 *
	 * @param string $git     github|bitbucket|gitlab|gitea).
	 * @param string $request API request.
	 *
	 * @return bool
	 */
	public function get_remote_api_branches( $git, $request ) {
		$branches = [];
		$response = isset( $this->response['branches'] ) ? $this->response['branches'] : false;

		if ( $this->exit_no_update( $response, true ) ) {
			return false;
		}

		if ( ! $response ) {
			self::$method = 'branches';
			$response     = $this->api( $request );
			$response     = $this->parse_response( $git, $response );

			if ( $this->validate_response( $response ) ) {
				return false;
			}

			if ( $response ) {
				$branches             = $this->parse_branch_response( $response );
				$this->type->branches = $branches;
				$this->set_repo_cache( 'branches', $branches );

				return true;
			}
		}

		$this->type->branches = $response;

		return true;
	}

	/**
	 * Get API release asset download link.
	 *
	 * @param  string $git     (github|bitbucket|gitlab|gitea).
	 * @param  string $request Query for API->api().
	 * @return string $response Release asset URI.
	 */
	public function get_api_release_asset( $git, $request ) {
		$response = isset( $this->response['release_asset'] ) ? $this->response['release_asset'] : false;

		if ( $response && $this->exit_no_update( $response ) ) {
			return false;
		}

		if ( ! $response ) {
			self::$method = 'release_asset';
			$response     = $this->api( $request );
			$response     = $this->parse_release_asset( $git, $request, $response );

			if ( ! $response && ! is_wp_error( $response ) ) {
				$response          = new \stdClass();
				$response->message = 'No release asset found';
			}
		}

		if ( $response && ! isset( $this->response['release_asset'] ) ) {
			$this->set_repo_cache( 'release_asset', $response );
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		return $response;
	}
}
