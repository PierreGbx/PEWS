<?php
/*	
 *------------------------------------------------------------
 *															
 *	PEWS (pew! pew!) - PHP Easy WebFinger Server 1.8.2
 *
 *	This script enables webfinger support on a server that
 *	handles one or more domains.
 *
 *	by Josh Panter <joshu at unfettered dot net>
 *
 *------------------------------------------------------------
*/
/*
	CONFIG
*/
// Set an alternate location for the data store. note: no trailing slash
define( 'PEWS_DATA_STORE', 'store' );
//------------------ DO NOT EDIT ANYTHING BELOW THIS LINE (Unless you REALLY mean it!) ------------------//
// Begin PEWS server
$req = $_SERVER['REQUEST_METHOD'];
if ($req === 'GET') {
	// are we receiving a JSON object?
	function isValidJSON($str) {
	   json_decode($str);
	   return json_last_error() == JSON_ERROR_NONE;
	}
	$json_params = file_get_contents("php://input");
	if (strlen($json_params) > 0 && isValidJSON($json_params)) {
		$rels = false;
		$json_object = true;
		$json_params = str_replace("{", "", $json_params);
		$json_params = str_replace("}", "", $json_params);
		$json_params  = explode(',', $json_params);
		foreach($json_params as $jp) {
			$jp = str_replace('"', '', $jp);
			$jp  = explode(':', $jp, 2);
			if($jp[0]=='resource') $_GET['resource']=$jp[1];
			if($jp[0]=='rel') $rels[]=$jp[1];
		}
	} else { $json_object = false; }
	// JSON object or not, ready to process data
	if( isset($_GET['resource'])) {
		$querry = $_GET['resource'];
		$subject  = explode(':', $querry);
		$case = $subject[0];
		if($case !== 'acct') { 
			$acctAliasMap = PEWS_DATA_STORE . '/acctAliasMap.json';
			if (file_exists($acctAliasMap)) {
				$acctAliasArray = json_decode(file_get_contents($acctAliasMap), true);
				if(array_key_exists($querry, $acctAliasArray)) {
					$subjectOverride = $querry;
					$aliasOverride = $acctAliasArray[$querry];
					$case = 'acct';
				}
			}
		} 
		if($case == 'acct' ) {
			$resource = isset($aliasOverride) ? pews_parse_account_string($aliasOverride) : pews_parse_account_string($subject[1]);
			$acct_file = PEWS_DATA_STORE."/".$resource['host']."/".$resource['user'].".json";
			// is there an account on file?
			if (file_exists($acct_file)) {
				// retrieve resource file and remove PEWS info
				$data = json_decode(file_get_contents($acct_file), true);
				if(isset($data['PEWS'])) unset($data['PEWS']);
				// is this an alias request?
				if(isset($subjectOverride) && isset($aliasOverride)) {
					$resAA = $data['aliases'];
					if (($key = array_search($subjectOverride, $resAA)) !== false) {
						unset($resAA[$key]);
					}
					$resAA[] = $aliasOverride;
					$data['subject'] = $subjectOverride;
					$data['aliases'] = $resAA;
				}
				// check for rel request
				if($json_object == false) {
					if( isset($_GET['rel'])) {
						// disect string for multiple 'rel' values
						$query  = explode('&', $_SERVER['QUERY_STRING']);
						$array = array();
						foreach( $query as $param ) {
							list($key, $value) = explode('=', $param, 2);
							if($key == 'rel') {
								$array[urldecode($key)][] = urldecode($value);
								$rels = $array['rel'];
							}
						}
					}
				} 
				if(isset($rels) && $rels !== false) {
					// check resource data against rel request
					$links = $data['links'];
					$result = null;
					foreach($rels as $rel) {
						foreach($links as $link) if($link['rel'] == $rel) $result[] = $link;
					}
					$data['links'] = ($result == null) ? $data['links'] : $result;
					$return = $data;
				} else {
					$return = $data;
				}
				// set return headers, response code, and return data
	 			header('Access-Control-Allow-Origin: *');
				http_response_code(200);
			} else {
				http_response_code(404);
				$return['statusCode'] = 404;
				$return['message']    = 'Account ' . $resource['acct'] . ' not found.';
			}
		} else {
			http_response_code(404);
			$return['statusCode'] = 404;
			$return['message']    = 'Resource ' . $querry . ' not found.';
		}
	} else {
		http_response_code(400);
		$return['statusCode'] = 400;
		$return['message']    = "Missing 'resource' parameter, please check your query.";
	}
	header("Content-Type: application/json");
	print json_encode($return, JSON_UNESCAPED_SLASHES);
	die();
// ----------- Begin PEWS manager -----------//
} elseif ($req === 'POST') {
	// are we receiving a JSON object?
	function isValidJSON($str) {
	   json_decode($str);
	   return json_last_error() == JSON_ERROR_NONE;
	}
	$json_params = file_get_contents("php://input");
	if (strlen($json_params) > 0 && isValidJSON($json_params))
  		$_POST = json_decode($json_params, true);
	// JSON object or not, ready to process data
	if(isset($_POST['pass'])) {
		$pass = $_POST['pass'];
		if (isset($_POST['auth'])) {
			$user = $_POST['auth'];
			$auth = pews_auth($user, $pass);
			$class = $auth['class'];
			if(!$class) {
				http_response_code(401);
				$return['info'] = $auth['info'];
			} elseif($class == 'user') {
				http_response_code(403);
				$return['info'] = 'forbidden: contact admin for access';
			} else  $return = pews_manager($class, false);
		} else $return = pews_manager(false, $pass);
	} else {
		http_response_code(403);
		$return['info'] = 'forbidden: credentials required';
	}
	header("Content-Type: application/json");
	print json_encode($return, JSON_UNESCAPED_SLASHES);
	die();
// ----------- Begin PEWS Fail -----------//
} else {
	header("Content-Type: application/json");
	http_response_code(405);
	print json_encode(array(
			'statusCode' => 405,
			'info' => 'method not allowed'
	), JSON_UNESCAPED_SLASHES);
	die();
}
// ----------- Begin PEWS functions -----------//
function pews_parse_account_string ( $acct ) {
	if(strpos($acct, '@')) {
		$parts = explode('@', $acct );
		$user = preg_replace('/^((\.*)(\/*))*/', '', $parts[0]);
		$host = preg_replace('/^((\.*)(\/*))*/', '', $parts[1]);
	} else {
		$user = preg_replace('/^((\.*)(\/*))*/', '', $acct);
		$host = $_SERVER['HTTP_HOST'];
		$acct = $user . '@' . $host;
	}
	$return['user'] = $user;
	$return['host'] = $host;
	$return['acct'] = $acct;
	return $return;
}
function pews_auth( $resource, $key ) {
	$resource = pews_parse_account_string( $resource );
	$acct_file = PEWS_DATA_STORE ."/". $resource['host'] . "/" . $resource['user'] .".json";
	// is there an account on file?
	if(file_exists($acct_file)) {
		$data 		= json_decode(file_get_contents($acct_file), true);
		$userData	= $data['PEWS'];
		$class 		= $userData['class'];
		$lock 		= $userData['pass'];
		if(strpos($lock, 'pews-hashed') === false ) {
			$hashit = pews_hash_pass($acct_file);
			if($hashit['is'] !== true ) die($hashit['info']);
			if($lock == $key ) {
				$return['info'] = $hashit['info'];
				$return['class'] = $class;
			} else {
				$return['info'] = 'bad password';
				$return['class'] = false;
			}
		} else {
			$hashLock = explode(':', $lock);
			$hashLock = $hashLock[1];
			if(password_verify($key, $hashLock)) {
				$return['info'] = 'success';
				$return['class'] = $class;
			} else {
				$return['info'] = 'bad password';
				$return['class'] = false;
			}
		}
	} else {
		$return['info'] = 'bad user name';
		$return['class'] = false;
	}
	return $return;
}
function pews_hash_pass($acct_file) {
	$data = json_decode(file_get_contents($acct_file), true);
	if($data == false) {
		$return['is'] = false;
		$return['info'] = 'Could not read auth file';
	} else {
		$userData = $data['PEWS'];
		$class = $userData['class'];
		$lock = $userData['pass'];
		$to_hash = 0;

		if(strpos($lock, 'pews-hashed') === false) {
			$to_hash++;
			$hash = password_hash( $lock, PASSWORD_DEFAULT);
			$data['PEWS'] = array('class' => $class, 'pass' => 'pews-hashed:'.$hash);
		}

		if($to_hash == 0) {
			$return['is'] = true; 
			$return['info'] = 'Nothing to hash';
		} else {
			$data = json_encode($data, JSON_UNESCAPED_SLASHES);
			$success = file_put_contents( $acct_file, $data );
			if($success === false) {
				$return['is'] = false;
				$return['info'] = 'Could not write to auth file';
			} else {
				$return['is'] = true;
				$return['info'] = 'password hashed';
			}
		}
	}
	return $return;
}
function pews_manager( $auth, $password ) {
	// add a new host to the server TODO url validations, etc
	if(isset($_POST['addHost'])) {
		if(isset($auth) && $auth == 'admin') {
			$host = preg_replace('/^((\.*)(\/*))*/', '', $_POST['addHost']);
			$new = PEWS_DATA_STORE . '/' . $host;
			if (!file_exists($new)){
				$make = mkdir($new);
				if(!$make) {
					http_response_code(500);
					$return['statusCode'] = 500;
					$return['message'] = 'host not created';
				} else {
				chmod( $new, 0755 );
					http_response_code(201);
					$return['statusCode'] = 201;
					$return['message'] = 'host: '. $host .' successfully added';
				}
			} else {
				http_response_code(200);
				$return['statusCode'] = 200;
				$return['message'] = 'host already present';
			}
		} else {
			http_response_code(403);
			$return['info'] = 'forbidden';
		}
	// delete a host AND all resources
	} elseif(isset($_POST['delHost'])) {
		if(isset($auth) && $auth == 'admin') {
			$host = preg_replace('/^((\.*)(\/*))*/', '', $_POST['delHost']);
			$old = PEWS_DATA_STORE . '/' . $host;
			if (file_exists($old)) {
				$files = glob($old.'/*');
				$acctAliasMap = PEWS_DATA_STORE . '/acctAliasMap.json';
				if(file_exists($acctAliasMap)) {
					$acctAliasArray = json_decode(file_get_contents($acctAliasMap), true);
					$did_del = 0;
					foreach($files as $file) {
						if(is_file($file)) {
							$fileData = json_decode(file_get_contents($file), true);
							if(isset($fileData['aliases'])) {
								$acctAliases = $fileData['aliases'];
								foreach($acctAliases as $alias) {
									if(array_key_exists($alias, $acctAliasArray)) { 
										unset($acctAliasArray[$alias]); 
										$did_del++;
									}
								}
							}
							unlink($file);
						}
					}
					if($did_del > 0) {
						file_put_contents( $acctAliasMap, json_encode($acctAliasArray, JSON_UNESCAPED_SLASHES) );
						chmod( $acctAliasMap, 0755 );
					}
				} else {
					foreach($files as $file) {
				  		if(is_file($file)) 
							unlink($file);
					}
				}
				$destroy = rmdir($old);
				if(!$destroy) {
					http_response_code(500);
					$return['statusCode'] = 500;
					$return['message'] = 'host not destroyed, but the accounts probably were.';
				} else {
					http_response_code(200);
					$return['statusCode'] = 200;
					$return['message'] = 'host: '.$host.' successfully removed';
				}
			} else {
				http_response_code(200);
				$return['statusCode'] = 200;
				$return['message'] = 'host already absent';
			}
		} else {
			http_response_code(403);
			$return['info'] = 'forbidden';
		}
	// Add a new resource account!
	} elseif(isset($_POST['addResource'])) {
		if(isset($auth) && $auth == 'admin') {
			$resource = pews_parse_account_string( $_POST['addResource'] );
			$newHost = PEWS_DATA_STORE . '/' . $resource['host'];
			if (!file_exists($newHost)){
				http_response_code(404);
				$return['statusCode'] = '404';
				$return['message'] = 'The host '. $resource['host'] .' is not present, and must be on this system before resource accounts are added to it.';
			} else {
				$newUser = $newHost .'/'. $resource['user'] .'.json';
				if (!file_exists($newUser)){
					$class 	= isset($_POST['setClass']) && ($_POST['setClass'] === 'admin' || $_POST['setClass'] === 'user') ? 
								$_POST['setClass'] : 
									'user';
					$pass= isset($_POST['setPass']) ? 'pews-hashed:'.password_hash($_POST['setPass'], PASSWORD_DEFAULT) : 'pewpewpassword';
					$data['PEWS'] = array( 'class' => $class, 'pass' => $pass );
					$data['subject'] = 'acct:'. $resource['acct'];
					if(isset($_POST['setAliases'])) {
						if(is_array($_POST['setAliases'])) $aliases = $_POST['setAliases'];
						else $aliases[] = $_POST['setAliases'];
						$data['aliases'] = $aliases;
					}
					if(isset($_POST['setProps'])) {
						if(is_array($_POST['setProps'])) {
							$data['properties'] = $_POST['setProps'];
						} elseif(isset($_POST['setPropKey']) && isset($_POST['setPropVal'])) {
							$data['properties'] = array($_POST['setPropKey'] => $_POST['setPropVal']);
						}
					}
					if(isset($_POST['setLinks'])) {
						if(is_array($_POST['setLinks'])) {
							$data['links'] = $_POST['setLinks'];
						} elseif(isset($_POST['setLinkRel']) && isset($_POST['setLinkHref'])) {
							$link['rel'] = $_POST['setLinkRel'];
							$link['href'] = $_POST['setLinkHref'];
							$link['type'] = isset($_POST['setLinkType']) ? $_POST['setLinkType'] : null;
							$link['titles'] = isset($_POST['setLinkTitles']) && is_array($_POST['setLinkTitles']) ? 
												$_POST['setLinkTitles'] : 
													isset($_POST['setLinkTitleLang']) && isset($_POST['setLinkTitle']) ? 
														array($_POST['setLinkTitleLang'] => $_POST['setLinkTitle']) :
															null;
							$link['properties'] = isset($_POST['setLinkProps']) && is_array($_POST['setLinkProps']) ? 
												$_POST['setLinkProps'] :
													isset($_POST['setLinkPropKey']) && isset($_POST['setLinkPropVal']) ? 
														array($_POST['setLinkPropKey'] => $_POST['setLinkPropVal']) :
															null;
							foreach($link as $k => $v) {
								if($v == null) unset($link[$k]);
							}
							$data['links'] = $link;							
						}
					}
					// Create the resource!!!
					$success = file_put_contents( $newUser, json_encode($data, JSON_UNESCAPED_SLASHES) );
					if(!$success) {
						http_response_code(500);
						$return['statusCode'] = 500;
						$return['message'] = 'Resource not created';
					} else {
						if(isset($aliases)){
							foreach($aliases as $alias) $acctAliasArray[$alias] = $resource['acct'];
							$acctAliasMap = PEWS_DATA_STORE . '/acctAliasMap.json';
							if(!file_exists($acctAliasMap)) {
								$dataAlt = $acctAliasArray;
							} else {
								$oldMap = json_decode(file_get_contents($acctAliasMap), true);
								$dataAlt = array_merge($oldMap , $acctAliasArray);
							}
							file_put_contents( $acctAliasMap, json_encode($dataAlt, JSON_UNESCAPED_SLASHES) );
							chmod( $acctAliasMap, 0755 );
						}
						chmod( $newUser, 0755 );
						http_response_code(201);
						$return['statusCode'] = 201;
						$return['message'] = 'Resource: '.$resource['acct'].' successfully added';
					}
				} else {
					http_response_code(200);
					$return['statusCode'] = 200;
					$return['message'] = 'Resource already present';
				}
			}
		} else {
			http_response_code(403);
			$return['info'] = 'forbidden';
		}
	// Remove a resource/account from the server
	} elseif(isset($_POST['delResource'])) {
		$resource = $_POST['delResource'];
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource, $password );
				$auth = $reauth['class'];
			case true:
				$resource = pews_parse_account_string( $resource );
				$acct_file = PEWS_DATA_STORE ."/". $resource['host'] ."/". $resource['user'] .".json";
				if (file_exists($acct_file)) {
						$acctArray = json_decode(file_get_contents($acct_file), true);
						$acctAliases = $acctArray['aliases'];
						$destroy = unlink($acct_file);
						if(!$destroy) {
							http_response_code(500);
							$return['statusCode'] = 500;
							$return['message'] = 'Server Error: resource not destroyed.';
						} else {
							$acctAliasMap = PEWS_DATA_STORE . '/acctAliasMap.json';
							if(file_exists($acctAliasMap)) {
								$acctAliasArray = json_decode(file_get_contents($acctAliasMap), true);
								$did_del = 0;
								foreach($acctAliases as $alias) {
									if(array_key_exists($alias, $acctAliasArray)) { 
										unset($acctAliasArray[$alias]); 
										$did_del++;
									}
								}
								if($did_del > 0) {
									file_put_contents( $acctAliasMap, json_encode($acctAliasArray, JSON_UNESCAPED_SLASHES) );
									chmod( $acctAliasMap, 0755 );
								}
							}
							http_response_code(200);
							$return['statusCode'] = 200;
							$return['message'] = 'Acct: '. $resource['acct'] .' successfully removed';
						}
				} else {
					http_response_code(200);
					$return['statusCode'] = 200;
					$return['message'] = 'Acct already absent';
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can delete your account if you know your credentials";
				$return['info'] = $reauth['info'];
		}
	// adding an alias to a resource
	} elseif(isset($_POST['addAlias'])) {
		$resource = $_POST['addAlias'];
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource, $password );
				$auth = $reauth['class'];
			case true:
				if(isset($_POST['newAlias'])) {
					$newAlias = $_POST['newAlias'];
					$resource = pews_parse_account_string( $resource );
					$acct_file = PEWS_DATA_STORE . '/' . $resource['host'] .'/'. $resource['user'] . '.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$aliases = isset($data['aliases']) ? $data['aliases'] : array();
						$aliases[] = $newAlias;
						$data['aliases'] = $aliases;
						$data = json_encode($data, JSON_UNESCAPED_SLASHES);
						$success = file_put_contents( $acct_file, $data );
						if($success === false) {
							http_response_code(500);
							$return['statusCode'] = 500;
							$return['message'] = 'Could not write to resource file';
						} else {
							if(isset($aliases)){
								foreach($aliases as $alias) $acctAliasArray[$alias] = $resource['acct'];
								$acctAliasMap = PEWS_DATA_STORE . '/acctAliasMap.json';
								if(!file_exists($acctAliasMap)) {
									$dataAlt = $acctAliasArray;
								} else {
									$oldMap = json_decode(file_get_contents($acctAliasMap), true);
									$dataAlt = array_merge($oldMap , $acctAliasArray);
								}
								file_put_contents( $acctAliasMap, json_encode($dataAlt, JSON_UNESCAPED_SLASHES) );
								chmod( $acctAliasMap, 0755 );
							}
							http_response_code(200);
							$return['statusCode'] = 200;
							$return['message'] = 'Alias: '.$newAlias.' added to '.$resource['acct'];
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account: '.$resource['acct'].' not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "Missing newAlias, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can add an alias if you know your credentials";
				$return['info'] = $reauth['info'];
		}
	// remove an alias from a resource
	} elseif(isset($_POST['delAlias'])) {
		$resource = $_POST['delAlias'];
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource, $password );
				$auth = $reauth['class'];
			case true:
				if(isset($_POST['oldAlias'])) {
					$oldAlias = $_POST['oldAlias'];
					$resource = pews_parse_account_string( $resource );
					$acct_file = PEWS_DATA_STORE . '/' . $resource['host'] .'/'. $resource['user'] . '.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$aliases = isset($data['aliases']) ? $data['aliases'] : null;

						if($aliases !== null && in_array( $oldAlias, $aliases ) ) {
							unset($aliases[$oldAlias]);
							if(empty($newAliasesArray)) { 
								unset ($data['aliases']);
							} else {
								$data['aliases'] = $newAliasesArray;
							}


							$data = json_encode($data, JSON_UNESCAPED_SLASHES);
							$success = file_put_contents( $acct_file, $data );
							if($success === false) {
								http_response_code(500);
								$return['statusCode'] = 500;
								$return['info'] = 'Could not write to resource file';
							} else {
								$acctAliasMap = PEWS_DATA_STORE . '/acctAliasMap.json';
								if(file_exists($acctAliasMap)) {
									$acctAliasArray = json_decode(file_get_contents($acctAliasMap), true);
									if(array_key_exists($oldAlias, $acctAliasArray)) {
										unset($acctAliasArray[$oldAlias]);
										file_put_contents( $acctAliasMap, json_encode($acctAliasArray, JSON_UNESCAPED_SLASHES) );
										chmod( $acctAliasMap, 0755 );
									}
								}
								http_response_code(200);
								$return['statusCode'] = 200;
								$return['info'] = 'Alias: '.$oldAlias.' removed '.$resource['acct'];
							}
						} else {
							http_response_code(100);
							$return['is'] = false;
							$return['info'] = 'Nothing to do: Alias '.$oldAlias.' not found.';
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account: '.$resource['acct'].' not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "Missing oldAlias, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can remove an alias if you know your credentials";
				$return['info'] = $reauth['info'];
		}
	} elseif(isset($_POST['addProp'])) {
		$resource = $_POST['addProp'];
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource, $password );
				$auth = $reauth['class'];
			case true:
				if(isset($_POST['propKey']) && isset($_POST['propVal'])) {
					$propKey = $_POST['propKey'];
					$propVal = $_POST['propVal'];
					$resource = pews_parse_account_string( $resource );
					$acct_file = PEWS_DATA_STORE .'/'. $resource['host'] .'/'. $resource['user'] .'.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$oldProps = isset($data['properties']) ? $data['properties'] : array();
						if(array_key_exists($propKey, $oldProps)) {
							http_response_code(409);
							$return['statusCode'] = 409;
							$return['message'] = $propKey . ' exists as '. $oldProps[$propKey] .' . Use editProp to overwrite.';
						} else {
							$newProps = array($propKey => $propVal);
							$props = array_replace($oldProps, $newProps);
							$data['properties'] = $props;
							$data = json_encode($data, JSON_UNESCAPED_SLASHES);
							$success = file_put_contents( $acct_file, $data );
							if($success === false) {
								http_response_code(500);
								$return['statusCode'] = 500;
								$return['message'] = 'Could not write to resource file';
							} else {
								http_response_code(200);
								$return['statusCode'] = 200;
								$return['message'] = 'Property element added to '.$resource['acct'];
							}
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account '. $resource['acct'] .' not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "This function requires both propKey and propVal, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can only add new resource properties with correct credentials";
				$return['info'] = $reauth['info'];
		}
	} elseif(isset($_POST['editProp'])) {
		$resource = $_POST['editProp'];
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource, $password );
				$auth = $reauth['class'];
			case true:
				if(isset($_POST['propKey']) && isset($_POST['propVal'])) {
					$propKey = $_POST['propKey'];
					$propVal = $_POST['propVal'];
					$resource = pews_parse_account_string( $resource );
					$acct_file = PEWS_DATA_STORE .'/'. $resource['host'] .'/'. $resource['user'] .'.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$oldProps = isset($data['properties']) ? $data['properties'] : array();
						$newProps = array($propKey => $propVal);
						$props = array_replace($oldProps, $newProps);
						$data['properties'] = $props;
						$data = json_encode($data, JSON_UNESCAPED_SLASHES);
						$success = file_put_contents( $acct_file, $data );
						if($success === false) {
							http_response_code(500);
							$return['statusCode'] = 500;
							$return['message'] = 'Could not write to resource file';
						} else {
							http_response_code(200);
							$return['statusCode'] = 200;
							$return['message'] = 'Property for'. $resource['acct'] .' updated.';
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account '. $resource['acct'] .' not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "This function requires both propKey and propVal, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can only edit resource properties with correct credentials";
				$return['info'] = $reauth['info'];
		}
	} elseif(isset($_POST['delProp'])) {
		$resource = $_POST['delProp'];
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource, $password );
				$auth = $reauth['class'];
			case true:
				if(isset($_POST['propKey'])) {
					$propKey = $_POST['propKey'];
					$resource = pews_parse_account_string( $resource );
					$acct_file = PEWS_DATA_STORE .'/'. $resource['host'] .'/'. $resource['user'] .'.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$props = isset($data['properties']) ? $data['properties'] : array();
						if(array_key_exists($propKey, $props)){
							unset($props[$propKey]);
							if(empty($props)) { 
								unset ($data['properties']);
							} else {
								$data['properties'] = $props;
							}
							$data = json_encode($data, JSON_UNESCAPED_SLASHES);
							$success = file_put_contents( $acct_file, $data );
							if($success === false) {
								http_response_code(500);
								$return['statusCode'] = 500;
								$return['message'] = 'Could not write to resource file';
							} else {
								http_response_code(200);
								$return['statusCode'] = 200;
								$return['message'] = 'Property for '. $resource['acct'] .' deleted.';
							}
						} else {
							http_response_code(200);
							$return['statusCode'] = 200;
							$return['message'] = 'Nothing to delete, property already absent from server.';
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account ['. $resource['acct'] .'] not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "Missing parameter: propKey, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can only delete resource properties with correct credentials";
				$return['info'] = $reauth['info'];
		}
	} elseif(isset($_POST['addLink'])) {
	   // Do Something    
	} elseif(isset($_POST['editLink'])) {
	   // Do Something    
	} elseif(isset($_POST['delLink'])) {
	   // Update a Password   
	} elseif(isset($_POST['updatePass'])) {
		$resource = $_POST['updatePass'];
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource, $password );
				$auth = $reauth['class'];
			case true:
				$resource = pews_parse_account_string( $resource );
				if(isset($_POST['newPass'])) {
					$newPass = $_POST['newPass'];
					$acct_file = PEWS_DATA_STORE .'/'. $resource['host'] .'/'. $resource['user'] .'.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$userData = $data['PEWS'];
						$class = $userData['class'];
						$hash = password_hash( $newPass, PASSWORD_DEFAULT);
						$data['PEWS'] = array('class' => $class, 'pass' => 'pews-hashed:'.$hash);
						$data = json_encode($data, JSON_UNESCAPED_SLASHES);
						$success = file_put_contents( $acct_file, $data );
						if($success === false) {
							$return['is'] = false;
							$return['info'] = 'Could not write to auth file';
						} else {
							$return['is'] = true;
							$return['info'] = 'password updated';
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account ['. $resource['acct'] .'] not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "Missing newPass, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can add only change your own password with correct credentials";
				$return['info'] = $reauth['info'];
		}
	} else {
		http_response_code(400);
		$return['statusCode'] = 400;
		$return['message']    = "Missing parameter, please check your query,";
	}
	return $return;
}
?>
