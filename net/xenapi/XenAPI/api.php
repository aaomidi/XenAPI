<?php
/*
 * This file is part of XenAPI <http://www.xenapi.net/>.
 *
 * XenAPI is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * XenAPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
$time_start = microtime(TRUE);
$options = array('api_key' => 'API_KEY');
$restAPI = new RestAPI($options); 

if ($restAPI->getAPIKey() != NULL && $restAPI->getAPIKey() == 'API_KEY') { 
    // API is set but not changed from the default API key.
    $restAPI->throwError(17);
} else if ($restAPI->getAPIKey() != NULL && !$restAPI->hasRequest('hash') && !$restAPI->isPublicAction()) {
    // Hash argument is required and was not found, throw error.
    $restAPI->throwError(3, 'hash');
} else if (!$restAPI->getHash() && !$restAPI->isPublicAction()) {
    // Hash argument is empty or not set, throw error. 
    $restAPI->throwError(1, 'hash');
} else if (!$restAPI->isAuthenticated() && !$restAPI->isPublicAction()) {
    // Hash is not valid and action is not public, throw error.
    $restAPI->throwError(6, $restAPI->getHash(), 'hash');
} else if (!$restAPI->hasRequest('action')) {
    // Action argument was not found, throw error.
    $restAPI->throwError(3, 'action');
} else if (!$restAPI->getAction()) {
    // Action argument is empty or not set, throw error. 
    $restAPI->throwError(1, 'action');
} else if (!$restAPI->isSupportedAction()) {
    // Action is not supported, throw error.
    $restAPI->throwError(2, $restAPI->getAction());
} else if (!$restAPI->isPermitted()) {
    // User does not have permission to use this action, throw error.
    if ($restAPI->hasRequest('value') && $restAPI->isUserAction()) {
        $restAPI->throwError(9, $restAPI->getAction());
    } else {
        $restAPI->throwError(10, $restAPI->getAction());
    }
}
// Process the request.
$restAPI->processRequest();

class RestAPI {
    const VERSION = '1.3.1';
    const GENERAL_ERROR = 0x201;
    const USER_ERROR = 0x202;
    /**
    * Contains all the actions in an array, each action is 'action' => 'permission_name'
    * 'action' is the name of the action in lowercase.
    * 'permission_name' is the permission requirement of the action, see description under.
    *
    * Permission names and meaning:
    *   - public:        A hash is not required to use this action, it can be used without
    *                    without being 'authenticated'.
    *   - authenticated: The action requires the user to be authenticated to use the action
    *                    with a 'value' argument.
    *   - moderator:     The action requires the user to be a moderator to use the action 
    *                    with a 'value' argument.
    *   - administrator: The action requires the user to be an administrator to use the action
    *                    with a 'value' argument.
    *   - private:       User is only allowed to use the action on himself/herself. 
    *                    Example: If user tries to use 'getAlerts' with a 'value' argument, 
    *                              an error will be thrown.
    *   - api_key:       An API key is required to perform this action.
    *
    * NOTE: Permissions are ignored when the API key is used as a hash, permissions are only
    *       used when the user is using the 'username:hash' format for the 'hash' argument.
    */
    private $actions = array(
        'authenticate'     => 'public',
        'edituser'         => 'api_key',
        'getactions'       => 'public', 
        'getaddon'         => 'administrator',
        'getaddons'        => 'administrator',
        'getalerts'        => 'private', 
        'getavatar'        => 'public',
        'getconversations' => 'private',
        'getgroup'         => 'public', 
        'getnode'          => 'public',
        'getnodes'         => 'public',
        'getpost'          => 'public',
        'getposts'         => 'public',
        'getprofilepost'   => 'authenticated',
        'getprofileposts'  => 'authenticated',
        'getresource'      => 'administrator',
        'getresources'     => 'administrator',
        'getstats'         => 'public',
        'getthread'        => 'public',
        'getthreads'       => 'public',
        'getuser'          => 'authenticated', 
        'getusers'         => 'public',
        'register'         => 'api_key'
    );
    
    // Array of actions that are user specific and require an username, ID or email for the 'value' parameter.
    private $user_actions = array('getalerts', 'getavatar', 'getconversations', 'getuser');
    
    // List of general errors, this is where the 'throwError' function gets the messages from.
    private $general_errors = array(
        0  => 'Unknown error', 
        1  => 'Argument: "{ERROR}", is empty/missing a value',
        2  => '"{ERROR}", is not a supported action',
        3  => 'Missing argument: "{ERROR}"',
        4  => 'No {ERROR} found with the argument: "{ERROR2}"',
        5  => 'Authentication error: "{ERROR}"',
        6  => '"{ERROR}" is not a valid {ERROR2}',
        7  => 'Something went wrong when "{ERROR}": "{ERROR2}"',
        8  => 'The request had no values set, available fields are: "({ERROR})"',
        9  => 'You are not permitted to use the "{ERROR}" action on others (remove the value argument)',
        10 => 'You do not have permission to use the "{ERROR}" action',
        11 => '"{ERROR}" is a supported action but there is no code for it yet',
        12 => '"{ERROR}" is a unknown request method',
        13 => '"{ERROR}" is not an installed addon',
        14 => '"{ERROR}" is not an author of any resources',
        15 => 'Could not find a resource with ID "{ERROR}"',
        16 => 'Could not find a required model to perform this request: "{ERROR}"',
        17 => 'The API key has not been changed, make sure you use another API key before using this API',
        18 => '"{ERROR} is a unknown permission name, the request was terminated.',
        19 => 'Could not find a {ERROR} with ID "{ERROR2}"',
        20 => '{ERROR} not have permissions to view {ERROR2}',
        21 => 'The "{ERROR}" argument has to be a number',
        22 => 'The argument for "order_by", "{ERROR}", was not found in the list available order by list: "({ERROR2})"',
        23 => 'The argument for "node_type", "{ERROR}", was not found in the list available node type list: "({ERROR2})"'
    );

    // Specific errors related to user actions.
    private $user_errors = array(
        0  => 'Unknown user error',
        1  => 'Field was not recognised',
        2  => 'Group not found',
        3  => 'User data array was missing',
        4  => 'The specified user is not registered',
        5  => 'Invalid custom field array',
        6  => 'Editing super admins is disabled',
        7  => 'The add_groups parameter needs to be an array and have at least 1 item',
        8  => 'The user is already a member of the group(s)',
        9  => 'No values were changed',
        10 => 'Missing required a required parameter',
        11 => 'The remove_groups parameter needs to be an array and have at least 1 item',
        12 => 'The user is not a member of the group(s)',
        30 => 'Missing required registration fields',
        31 => 'Password invalid',
        32 => 'Name length is too short',
        33 => 'Name length is too long',
        34 => 'Name contains disallowed words',
        35 => 'Name does not follow the required format',
        36 => 'Name contains censored words',
        37 => 'Name contains CTRL characters',
        38 => 'Name contains comma',
        39 => 'Name resembles an email',
        40 => 'User already exists',
        41 => 'Invalid email',
        42 => 'Email already used',
        43 => 'Email banned by administrator',
        44 => 'Invalid timezone',
        45 => 'Custom title contains censored words',
        46 => 'Custom title contains disallowed words',
        47 => 'Invalid date of birth',
        48 => 'Cannot delete your own account',
        49 => 'Field contained an invalid value'
    );

    private $xenAPI, $method, $data = array(), $hash = FALSE, $apikey = FALSE;

    /**
    * Default constructor for the RestAPI class.
    * The data gets set here depending on what kind of request method is being used.
    */
    public function __construct($options = array()) {
        $this->method = strtolower($_SERVER['REQUEST_METHOD']);  
        switch ($this->method) {  
            case 'get':  
                $this->data = $_GET;  
                break;  
            case 'post':  
                $this->data = $_POST;  
                break;  
            case 'put':  
            case 'delete':
                parse_str(file_get_contents('php://input'), $put_vars);  
                $this->data = $put_vars;  
                break;  
            default:
                $this->throwError(12, $this->method);
                break;
        }
        $this->xenAPI = new XenAPI();

        // Check if there are any options.
        if (is_array($options) && count($options) > 0) {
            if (isset($options['api_key']) && !empty($options['api_key'])) {
                // Set the API key.
                $this->apikey = $options['api_key'];
            }
        }

        // Lowercase the key data, ignores the case of the arguments.
        $this->data = array_change_key_case($this->data);
        if ($this->hasRequest('hash')) {
            // Sets the hash variable if the hash argument is set.
            $this->hash = $this->getRequest('hash');
        }
        // Check if grab_as by argument is set.
        if ($this->hasRequest('grab_as') && $this->hasAPIKey()) {
            if (!$this->getRequest('grab_as')) {
                // Throw error if the 'grab_as' argument is set but empty.
                $this->throwError(1, 'grab_as');
            }
            // Create a user object with the 'grab_as' argument.
            $this->grab_as = $this->xenAPI->getUser($this->getRequest('grab_as'));
            if (!$this->grab_as->isRegistered()) {
                // Throw error if the 'grab_as' user is not registered.
                $this->throwError(4, 'user', $this->getRequest('grab_as'));
                break;
            }
        }
        // Check if order by argument is set.
        if ($this->hasRequest('order_by')) {
            if (!$this->getRequest('order_by')) {
                // Throw error if the 'order_by' argument is set but empty.
                $this->throwError(1, 'order_by');
            }
            $this->order_by = $this->getRequest('order_by');
        }
        // Check if order argument is set.
        if ($this->hasRequest('order')) {
            if (!$this->getRequest('order')) {
                // Throw error if the 'order' argument is set but empty.
                $this->throwError(1, 'order');
            }
            $this->order = strtolower($this->getRequest('order'));
            if ($this->order == 'd' || $this->order == 'desc' || $this->order == 'descending') {
                // Order is descending (10-0).
                $this->order = 'desc';
            } else if ($this->order == 'a' || $this->order == 'asc' || $this->order == 'ascending') {
                // Order is ascending (0-10).
                $this->order = 'asc';
            } else {
                // Order is unknown, default to descending (10-0).
                $this->order = 'desc';
            }
        } else {
            // Order is not set, default to descending (10-0).
            $this->order = 'desc';
        }
        // Set the limit.
        $this->setLimit(100);
    }

    /**
    * Returns the XenAPI, returns NULL if the XenAPI was not set.
    */
    public function getXenAPI() {
        return $this->xenAPI;
    }

    /**
    * Returns the API key, returns FALSE if an API key was not set.
    */
    public function getAPIKey() {
        return $this->apikey;
    }
    
    /**
    * Sets the API key.
    */
    public function setAPIKey($apikey) {
        $this->apikey = $apikey;
    }
    
    /**
    * Return the hash, returns FALSE if the hash was not set.
    */
    public function getHash() {
        return $this->hash;
    }

    /**
    * TODO
    */
    public function setLimit($default) {
        // Check if limit argument is set.
        if ($this->hasRequest('limit')) {
            if (!$this->getRequest('limit') && (is_numeric($this->getRequest('limit')) && $this->getRequest('limit') != 0)) {
                // Throw error if the 'limit' argument is set but empty.
                $this->throwError(1, 'limit');
            } else if (!is_numeric($this->getRequest('limit'))) {
                // Throw error if the 'limit' argument is set but not a number.
                $this->throwError(21, 'limit');
            }
            $this->limit = $this->getRequest('limit');
        } else {
            // Limit is not set, default to $default variable.
            $this->limit = $default;
        }
    }
    
    /**
    * Checks if the request is authenticated.
    * Returns TRUE if the hash equals the API key.
    * Returns TRUE if the hash equals the user hash.
    * Returns FALSE if none of the above are TRUE.
    */
    public function isAuthenticated() {
        if ($this->getHash()) {
            // Hash argument is set, continue.
            if ($this->getHash() == $this->getAPIKey()) {
                // The hash equals the API key, return TRUE.
                return TRUE;
            }
            if (strpos($this->getHash(), ':') !== FALSE) {
                // The hash contains : (username:hash), split it into an array.
                $array = explode(':', $this->getHash());
                // Get the user and check if the user is valid (registered).
                $user = $this->xenAPI->getUser($array[0]);
                if ($user->isRegistered()) {
                    // User is registered, get the hash from the authentication record.
                    $record = $user->getAuthenticationRecord();
                    $ddata = unserialize($record['data']);
                    if ($ddata['hash'] == $array[1]) {
                        // The hash in the authentication record equals the hash in the 'hash' argument.
                        return TRUE;
                    }
                }
            }
        }
        return FALSE;
    }
    
    /**
    * Checks if the request is permitted.
    * Returns TRUE if API key is set and valid or the action's permission is public.
    */
    public function isPermitted() {
        $permission = $this->getActionPermission();
        // Check if the request is authenticated and it's an user hash (and not an API key).
        if ($this->isAuthenticated() && $this->getUser()) {
            switch ($permission) {
                case 'public':
                    return TRUE;
                case 'authenticated':
                    return TRUE;
                case 'moderator':
                    return $this->getUser()->isModerator();
                case 'administrator':
                    return $this->getUser()->isAdmin();
                case 'private':
                    // Check if the 'value' argument is set.
                    if ($this->hasRequest('value') && $this->getRequest('value')) {
                        /**
                        * Returns TRUE if the 'value' argument equals the username of the user hash.
                        * In other words, the request is not permitted if 'value' != username.
                        */
                        return $this->getUser()->getUsername() == $this->getRequest('value');
                    }
                    // The value argument is not, request is permitted, return TRUE.
                    return TRUE;
                case 'api_key':
                    return $this->hasAPIKey();
                default:
                    $this->throwError(10, $this->getAction());
                    return FALSE;
            }
        }
        // Returns TRUE if permission of the action is public or the request has a valid API key.
        return $permission == 'public' || $this->hasAPIKey();
    }

    public function getCustomArray($input_data) {
        // Custom fields are set.
        $custom_array_data = array();

        // Check if there are more than one custom array.
        if (strpos($input_data, ',') !== FALSE) {
            // There are more than one custom array set.
            $custom_arrays = explode(',', $input_data);

            // Loop through the custom fields.
            foreach ($custom_arrays as $custom_array) {
                // Check if custom array string contains = symbol, ignore if not.
                if (strpos($custom_array, '=') !== FALSE) {
                    // Custom array string contains = symbol, explode it.
                    $custom_array_list = explode('=', $custom_array);

                    // Add the custom item data to the array.
                    $custom_array_data[$custom_array_list[0]] = $custom_array_list[1];
                }
            }
        } else if (strpos($input_data, '=') !== FALSE) {
            // Custom array string contains = symbol, explode it.
            $custom_array_list = explode('=', $input_data);

            // Add the custom item data to the array.
            $custom_array_data[$custom_array_list[0]] = $custom_array_list[1];
        }

        // Return the array(s).
        return $custom_array_data;
    }
    
    /**
    * Returns TRUE if the request has an API key that is valid, returns FALSE if not.
    */
    public function hasAPIKey() {
        if ($this->getHash()) {
            return $this->getHash() == $this->getAPIKey();
        }
        return FALSE;
    }
    
    /**
    * Returns the User class of the username if the hash is set and is an userhash.
    * Returns FALSE if the hash is not an userhash.
    */
    public function getUser() {
        if (isset($this->user) && $this->user != NULL) {
            return $this->user;
        } else if (isset($this->grab_as) && $this->grab_as != NULL) {
            return $this->grab_as;
        } else if (strpos($this->getHash(), ':') !== FALSE) {
            $array = explode(':', $this->getHash());
            $this->user = $this->xenAPI->getUser($array[0]);
            return $this->user;
        }    
        return FALSE;
    }

    /**
    *
    */
    public function checkOrderBy($order_by_array) {
        if ($this->hasRequest('order_by')) {
            if (!$this->getRequest('order_by')) {
                // Throw error if the 'order_by' argument is set but empty.
                $this->throwError(1, 'order_by');
                break;
            } 
            if (!in_array(strtolower($this->getRequest('order_by')), $order_by_array)) {
                // Throw error if the 'order_by' argument is set but could not be found in list of allowed order_by.
                $this->throwError(22, $this->getRequest('order_by'), implode(', ', $order_by_array));
                break;
            }
            return strtolower($this->getRequest('order_by'));
        }
        return FALSE;
    }
    
    /**
    * Returns the method (get, post, put, delete).
    */
    public function getMethod() {
        return $this->method;
    }
    
    /**
    * Returns the action name.
    */
    public function getAction() {
        return $this->getRequest('action');
    }
    
    /**
    * Returns the permission name of the action.
    */
    public function getActionPermission() {
        return (isset($this->data['action']) && isset($this->actions[strtolower($this->getAction())])) 
                ? strtolower($this->actions[strtolower($this->getAction())]) 
                : NULL;
    }
    
    /**
    * Returns TRUE if the '$key' is set a argument, returns FALSE if not.
    */
    public function hasRequest($key) {
        return isset($this->data[strtolower($key)]);
    }
    
    /**
    * Gets the data of the '$key', returns FALSE if the '$key' argument was not set.
    */
    public function getRequest($key) {
        if ($this->hasRequest($key)) {
            return $this->data[strtolower($key)];
        } else {
            return FALSE;
        }
    }
    
    /**
    * Returns the array of all the arguments in the request.
    */
    public function getData() {
        return $this->data;
    }
    
    /**
    * Returns TRUE if the action is supported, FALSE if not.
    */
    public function isSupportedAction() {
        return isset($this->data['action']) && array_key_exists(strtolower($this->data['action']), $this->actions);
    }
    
    /**
    * Returns TRUE if the action is a public action (does not require a hash), FALSE if not.
    */
    public function isPublicAction() {
        return isset($this->data['action']) && isset($this->actions[strtolower($this->data['action'])]) && strtolower($this->actions[strtolower($this->data['action'])]) == 'public';
    }
    
    /**
    * Returns TRUE if the action is a user action (the 'value' parameter has to be an username/id/email), FALSE if not.
    */
    public function isUserAction() {
        return isset($this->data['action']) && in_array(strtolower($this->data['action']), $this->user_actions);
    }

    /**
    * TODO
    */
    public function checkRequestParameter($parameter, $required = TRUE) {
        if ($required && !$this->hasRequest($parameter)) {
            // The '$parameter' argument has not been set, throw error.
            $this->throwError(3, $parameter);
        } else if ($this->hasRequest($parameter) && !$this->getRequest($parameter)) {
            // Throw error if the '$parameter' argument is set but empty.
            $this->throwError(1, $parameter);
        }
        return TRUE;
    }

    /**
    * TODO
    */
    public function checkRequestParameters(array $parameters, $required = TRUE) {
        foreach ($parameters as $parameter) {
            $this->checkRequestParameter($parameter, $required);
        }
        return TRUE;
    }

    /**
    * TODO
    */
    public function getUserErrorID($phrase_name) {
        switch ($phrase_name) {
            case 'please_enter_value_for_all_required_fields':
                return 30;
            case 'please_enter_valid_password':
                return 31;
            case 'please_enter_name_that_is_at_least_x_characters_long':
                return 32;
            case 'please_enter_name_that_is_at_most_x_characters_long':
                return 33;
            case 'please_enter_another_name_disallowed_words':
                return 34;
            case 'please_enter_another_name_required_format':
                return 35;
            case 'please_enter_name_that_does_not_contain_any_censored_words':
                return 36;
            case 'please_enter_name_without_using_control_characters':
                return 37;
            case 'please_enter_name_that_does_not_contain_comma':
                return 38;
            case 'please_enter_name_that_does_not_resemble_an_email_address':
                return 39;
            case 'usernames_must_be_unique':
                return 40;
            case 'please_enter_valid_email':
                return 41;
            case 'email_addresses_must_be_unique':
                return 42;
            case 'email_address_you_entered_has_been_banned_by_administrator':
                return 43;
            case 'please_select_valid_time_zone':
                return 44;
            case 'please_enter_custom_title_that_does_not_contain_any_censored_words':
                return 45;
            case 'please_enter_another_custom_title_disallowed_words':
                return 46;
            case 'please_enter_valid_date_of_birth':
                return 47;
            case 'you_cannot_delete_your_own_account':
                return 48;
            case 'please_enter_valid_value':
                return 49;
            default:
                return 0;
        }
    }

    /**
    * Gets the error message and replaces {ERROR} with the $extra parameter.
    */
    public function getError($error, $extra = NULL, $extra2 = NULL, $error_type = self::GENERAL_ERROR) {
        if ($error_type == NULL) {
            $error_type = self::GENERAL_ERROR;
        }
        if ($error_type == self::GENERAL_ERROR) {
            if (!array_key_exists($error, $this->general_errors)) {
                $error = 0;
            }
            $error_string = $this->general_errors[$error];
        } else if ($error_type == self::USER_ERROR) {
            if (!array_key_exists($error, $this->user_errors)) {
                $error = 0;
            }
            $error_string = $this->user_errors[$error];
        }
        if ($extra != NULL) {
            $error_string = str_replace('{ERROR}', $extra, $error_string);
        } 
        if ($extra2 != NULL) {
            $error_string = str_replace('{ERROR2}', $extra2, $error_string);
        }
        return array('id' => $error, 'message' => $error_string);
    }
    
    /**
    * Throw the error message.
    */
    public function throwError($error, $extra = NULL, $extra2 = NULL) {
        if ($error == self::USER_ERROR) {
            $user_error = $this->getError($extra['error_id'], NULL, NULL, self::USER_ERROR);
            $general_error = $this->getError(7, 'registering user', $user_error['message']);
            $error_response = array(
                'error' => $general_error['id'], 
                'message' => $general_error['message'], 
                'user_error_id' => $user_error['id'],
                'user_error_field' => $extra['error_field'],
                'user_error_key' => $extra['error_key'],
                'user_error_phrase' => $extra['error_phrase']
            );
        } else {
            if (is_array($extra)) {
                $extra = implode(', ', $extra);
            }
            if (is_array($extra2)) {
                $extra2 = implode(', ', $extra2);
            }
            $error = $this->getError($error, $extra, $extra2, $error_type);
            $error_response = array('error' => $error['id'], 'message' => $error['message']);
        }
        // Throw a 400 error.
        header('HTTP/ 400 API error');

        // Send error.
        $this->sendResponse($error_response);
    }
    
    /**
    * Processes the REST request.
    */
    public function processRequest() {
        // Check if the action is an user action.
        if ($this->isUserAction()) {
            if ($this->hasRequest('value')) {
                if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                // Create a user variable with the 'value' argument.
                $user = $this->xenAPI->getUser($this->getRequest('value'));
                if (!$user->isRegistered()) {
                    // Throw error if the 'value' user is not registered.
                    $this->throwError(4, 'user', $this->getRequest('value'));
                    break;
                }
            } else if ($this->hasRequest('hash')) {
                // The 'value' argument was not set, check if hash is an API key.
                if ($this->hasAPIKey() && !isset($this->grab_as)) {
                    /**
                    * The 'hash' argument is an API key, and the 'value' argument
                    * is required but not set, throw error.
                    */
                    $this->throwError(3, 'value');
                }
                // Get the user from the hash.
                $user = $this->getUser();
            } else {
                // Nor the 'value' argument or the 'hash' argument has been set, throw error.
                $this->throwError(3, 'value');
                break;
            }
        }
    
        switch (strtolower($this->getAction())) {
            case 'authenticate': 
                /**
                * Authenticates the user and returns the hash that the user has to use for future requests.
                *
                * EXAMPLE:
                *   - api.php?action=authenticate&username=USERNAME&password=PASSWORD
                */
                if (!$this->hasRequest('username')) {
                    // The 'username' argument has not been set, throw error.
                    $this->throwError(3, 'username');
                    break;
                } else if (!$this->getRequest('username')) {
                    // Throw error if the 'username' argument is set but empty.
                    $this->throwError(1, 'username');
                    break;
                } else if (!$this->hasRequest('password')) {
                    // The 'password' argument has not been set, throw error.
                    $this->throwError(3, 'password');
                    break;
                } else if (!$this->getRequest('password')) {
                    // Throw error if the 'password' argument is set but empty.
                    $this->throwError(1, 'password');
                    break;
                }
                // Get the user object.
                $user = $this->xenAPI->getUser($this->getRequest('username'));
                if (!$user->isRegistered()) {
                    // Requested user was not registered, throw error.
                    $this->throwError(4, 'user', $this->getRequest('username'));
                } else {
                    // Requested user was registered, check authentication.
                    if ($user->validateAuthentication($this->getRequest('password'))) {
                        // Authentication was valid, grab the user's authentication record.
                        $record = $user->getAuthenticationRecord();
                        $ddata = unserialize($record['data']);
                        // Send the hash in responsel.
                        $this->sendResponse(array('hash' => $ddata['hash']));
                    } else {
                        // The username or password was wrong, throw error.
                        $this->throwError(5, 'Invalid username or password!');
                    }
                }
                break;
            case 'edituser':
                /**
                * Edits the user.
                */
                if (!$this->hasRequest('user')) {
                    // The 'user' argument has not been set, throw error.
                    $this->throwError(3, 'user');
                    break;
                } else if (!$this->getRequest('user')) {
                    // Throw error if the 'user' argument is set but empty.
                    $this->throwError(1, 'user');
                    break;
                }
                if ($this->hasRequest('custom_field_identifier')) {
                    if (!$this->getRequest('custom_field_identifier')) {
                        // Throw error if the 'custom_field_identifier' argument is set but empty.
                        $this->throwError(1, 'custom_field_identifier');
                        break;
                    }
                    $user = $this->getXenAPI()->getUser($this->getRequest('user'), array('custom_field' => $this->getRequest('custom_field_identifier')));
                } else {
                    // Get the user object.
                    $user = $this->getXenAPI()->getUser($this->getRequest('user'));
                }
                if (!$user->isRegistered()) {
                    // Requested user was not registered, throw error.
                    $this->throwError(4, 'user', $this->getRequest('user'));
                }

                // Init the edit array.
                $edit_data = array();

                if ($this->hasRequest('group')) {
                    // Request has value.
                    if (!$this->getRequest('group')) {
                        // Throw error if the 'group' argument is set but empty.
                        $this->throwError(1, 'group');
                        break;
                    }
                    $group = $this->getXenAPI()->getGroup($this->getRequest('group'));
                    if (!$group) {
                        $edit_error = array(
                            'error_id' => 2,
                            'error_key' => 'group_not_found', 
                            'error_field' => 'group', 
                            'error_phrase' => 'Could not find group with parameter "' . $this->getRequest('group') . '"'
                        );
                        $this->throwError(self::USER_ERROR, $edit_error);
                    }
                    // Set the group id of the edit.
                    $edit_data['group_id'] = $group['user_group_id'];
                }

                $group_fields = array('add_groups', 'remove_groups');
                foreach ($group_fields as $group_field) {
                    if (!$this->hasRequest($group_field)) {
                        continue;
                    }
                    // Request has value.
                    if (!$this->getRequest($group_field)) {
                        // Throw error if the $group_field argument is set but empty.
                        $this->throwError(1, $group_field);
                    }
                    // Initialize the array.
                    $edit_data[$group_field] = array();

                    // Check if value is an array.
                    if (strpos($this->getRequest($group_field), ',') !== FALSE) {
                        // Value is an array, explode it.
                        $groups = explode(',', $this->getRequest($group_field));

                        // Loop through the group values.
                        foreach ($groups as $group_value) {
                            // Grab the group from the group value.
                            $group = $this->getXenAPI()->getGroup($group_value);

                            // Check if group was found.
                            if (!$group) {
                                // Group was not found, throw error.
                                $edit_error = array(
                                    'error_id' => 2,
                                    'error_key' => 'group_not_found', 
                                    'error_field' => $group_field, 
                                    'error_phrase' => 'Could not find group with parameter "' . $group_value . '" in array "' . $this->getRequest('add_group') . '"'
                                );
                                $this->throwError(self::USER_ERROR, $edit_error);
                            }
                            // Add the group_id to the the add_group array.
                            $edit_data[$group_field][] = $group['user_group_id'];
                        }
                    } else {
                        // Grab the group from the group value.
                        $group = $this->getXenAPI()->getGroup($this->getRequest($group_field));

                        // Check if group was found.
                        if (!$group) {
                            // Group was not found, throw error.
                            $edit_error = array(
                                'error_id' => 2,
                                'error_key' => 'group_not_found', 
                                'error_field' => $group_field, 
                                'error_phrase' => 'Could not find group with parameter "' . $this->getRequest($group_field) . '"'
                            );
                            $this->throwError(self::USER_ERROR, $edit_error);
                        }
                        // Add the group_id to the the add_groups array.
                        $edit_data[$group_field][] = $group['user_group_id'];
                    }
                }


                if ($this->hasRequest('custom_fields')) {
                    // Request has value.
                    if (!$this->getRequest('custom_fields')) {
                        // Throw error if the 'custom_fields' argument is set but empty.
                        $this->throwError(1, 'custom_fields');
                        break;
                    }
                    $custom_fields = $this->getCustomArray($this->getRequest('custom_fields'));

                    // Check if we found any valid custom fields, throw error if not.
                    if (count($custom_fields) == 0) {
                        // The custom fields array was empty, throw error.
                        $edit_error = array(
                            'error_id' => 5,
                            'error_key' => 'invalid_custom_fields', 
                            'error_field' => 'custom_fields', 
                            'error_phrase' => 'The custom fields values were invalid, valid values are: '
                                            . 'custom_fields=custom_field1=custom_value1,custom_field2=custom_value2 '
                                            . 'but got: "' . $this->getRequest('custom_fields') . '" instead'
                        );
                        $this->throwError(self::USER_ERROR, $edit_error);
                    }
                    $edit_data['custom_fields'] = $custom_fields;
                }

                // List of fields that are accepted to be edited.
                $edit_fields = array('username', 'password', 'email', 'gender', 'custom_title', 'style_id', 'timezone', 'visible', 'dob_day', 'dob_month', 'dob_year', 'user_state');

                // List of fields that the request should ignore.
                $ignore_fields = array('hash', 'action', 'user');

                // Let's check which fields are set.
                foreach ($this->data as $data_key => $data_item) {
                    if (!in_array($data_key, $ignore_fields) && in_array($data_key, $edit_fields) && $this->checkRequestParameter($data_key, FALSE)) {
                        $edit_data[$data_key] = $data_item;
                    }
                }

                if (count($edit_data) == 0) {
                    // There are no fields set, throw error.
                    $this->throwError(8, $edit_fields);
                }
               
                // Get edit results.
                $edit_results = $this->getXenAPI()->editUser($user, $edit_data);

                if (!empty($edit_results['error'])) {
                    // The registration failed, process errors.
                    if (is_array($edit_results['errors'])) {
                        // The error message was an array, loop through the messages.
                        $error_keys = array();
                        foreach ($edit_results['errors'] as $error_field => $error) {
                            if (!($error instanceof XenForo_Phrase)) {
                                $edit_error = array(
                                    'error_id' => 1,
                                    'error_key' => 'field_not_recognised', 
                                    'error_field' => $error_field, 
                                    'error_phrase' => $error
                                );
                                $this->throwError(self::USER_ERROR, $edit_error);

                            }

                            // Let's init the edit error array.
                            $edit_error = array(
                                'error_id' => $this->getUserErrorID($error->getPhraseName()),
                                'error_key' => $error->getPhraseName(), 
                                'error_field' => $error_field, 
                                'error_phrase' => $error->render()
                            );

                            $this->throwError(self::USER_ERROR, $edit_error);
                        }
                    } else {
                        $edit_error = array(
                            'error_id' => $edit_results['error'],
                            'error_key' => 'general_user_edit_error', 
                            'error_phrase' => $edit_results['errors']
                        );
                        $this->throwError(self::USER_ERROR, $edit_error);
                        // Throw error message.
                    }
                } else {
                    // Edit was successful, return results.
                    $this->sendResponse($edit_results);
                }
                break;
            case 'getactions':
                /**
                * Returns the actions and their permission levels.
                *
                * EXAMPLE:
                *   - api.php?action=getActions
                */
                /*
                
                // TODO: Only show actions depending on what permission level the user is.
                $temp = array();
                foreach ($this->actions as $action => $permission) {
                    $temp[$action] = $permission;
                }
                */
                // Send the response.
                $this->sendResponse($this->actions);
                break;
            case 'getaddon': 
                /**
                * Returns the addon information depending on the 'value' argument.
                *
                * NOTE: Only addon ID's can be used for the 'value' parameter.
                *       Addon ID's can be found by using the 'getAddons' action.
                *
                * EXAMPLE:
                *   - api.php?action=getAddon&value=PostRating&hash=USERNAME:HASH
                *   - api.php?action=getAddon&value=PostRating&hash=API_KEY
                */
                if (!$this->hasRequest('value')) {
                    // The 'value' argument has not been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                } else if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                $string = $this->getRequest('value');
                // Try to grab the addon from XenForo.
                $addon = $this->getXenAPI()->getAddon($string);
                if (!$addon->isInstalled()) {
                    // Could not find the addon, throw error.
                    $this->throwError(13, $string);
                } else {
                    // Addon was found, send response.
                    $this->sendResponse(Addon::getLimitedData($addon));
                }
                break;
            case 'getaddons':
                /**
                * Returns a list of addons, if a type is not specified or not supported, 
                * default (all) is used instead.
                *
                * Options for the 'type' argument are:
                *   - all:      This is default, and will return all the addons, ignoring if they are installed or not.
                *   - enabled:  Fetches all the addons that are enabled, ignoring the disabled ones.
                *   - disabled: Fetches all the addons that are disabled, ignoring the enabled ones.
                *
                * For more information, see /library/XenForo/Model/Alert.php.
                *
                * EXAMPLES: 
                *   - api.php?action=getAddons&hash=USERNAME:HASH
                *   - api.php?action=getAddons&hash=API_KEY
                *   - api.php?action=getAddons&type=enabled&hash=USERNAME:HASH
                *   - api.php?action=getAddons&type=enabled&hash=API_KEY
                */
                /* 
                * Check if the request has the 'type' argument set, 
                * if it doesn't it uses the default (all).
                */
                if ($this->hasRequest('type')) {
                    if (!$this->getRequest('type')) {
                        // Throw error if the 'type' argument is set but empty.
                        $this->throwError(1, 'type');
                        break;
                    }
                    // Use the value from the 'type' argument to get the alerts.
                    $installed_addons = $this->xenAPI->getAddons($this->getRequest('type'));
                } else {
                    // Use the default type to get the alerts.
                    $installed_addons = $this->xenAPI->getAddons();
                }
                // Create an array for the addons.
                $addons = array();
                // Loop through all the addons and strip out any information that we don't need.
                foreach ($installed_addons as $addon) {
                    $addons[] = Addon::getLimitedData($addon);
                }
                // Send the response.
                $this->sendResponse(array('count' => count($addons), 'addons' => $addons));
                break;
            case 'getalerts':
                /**
                * Grabs the alerts from the specified user, if type is not specified, 
                * default (recent alerts) is used instead.
                * 
                * NOTE: The 'value' argument will only work for the user itself and
                *       not on others users unless the permission argument for the 
                *       'getalerts' action is changed (default permission: private).
                *
                * Options for the 'type' argument are:
                *     - fetchPopupItems: Fetch alerts viewed in the last options:alertsPopupExpiryHours hours.
                *     - fetchRecent:     Fetch alerts viewed in the last options:alertExpiryDays days.
                *     - fetchAll:        Fetch alerts regardless of their view_date.
                *
                * For more information, see /library/XenForo/Model/Alert.php.
                *
                * EXAMPLES: 
                *   - api.php?action=getAlerts&hash=USERNAME:HASH
                *   - api.php?action=getAlerts&type=fetchAll&hash=USERNAME:HASH
                *   - api.php?action=getAlerts&value=USERNAME&hash=USERNAME:HASH
                *   - api.php?action=getAlerts&value=USERNAME&type=fetchAll&hash=USERNAME:HASH
                *   - api.php?action=getAlerts&value=USERNAME&hash=API_KEY
                *   - api.php?action=getAlerts&value=USERNAME&type=fetchAll&hash=API_KEY
                */
                /* 
                * Check if the request has the 'type' argument set, 
                * if it doesn't it uses the default (fetchRecent).
                */
                if ($this->hasRequest('type')) {
                    if (!$this->getRequest('type')) {
                        // Throw error if the 'type' argument is set but empty.
                        $this->throwError(1, 'type');
                        break;
                    }
                    // Use the value from the 'type' argument to get the alerts.
                    $data = $user->getAlerts($this->getRequest('type'));
                } else {
                    // Use the default type to get the alerts.
                    $data = $user->getAlerts();
                }
                // Send the response.
                $this->sendResponse($data);
                break;
            case 'getavatar': 
                /**
                * Returns the avatar of the requested user.
                *
                * Options for the 'size' argument are:
                *   - s (Small)
                *   - m (Medium)
                *   - l (Large)
                *
                * NOTE: The default avatar size is 'Medium'.
                * 
                * EXAMPLES: 
                *   - api.php?action=getAvatar&hash=USERNAME:HASH
                *   - api.php?action=getAvatar&size=M&hash=USERNAME:HASH
                *   - api.php?action=getAvatar&value=USERNAME&hash=USERNAME:HASH
                *   - api.php?action=getAvatar&value=USERNAME&size=M&hash=USERNAME:HASH
                *   - api.php?action=getAvatar&value=USERNAME&hash=API_KEY
                *   - api.php?action=getAvatar&value=USERNAME&size=M&hash=API_KEY
                */
                if ($this->hasRequest('size')) {
                    if (!$this->getRequest('size')) {
                        // Throw error if the 'size' argument is set but empty.
                        $this->throwError(1, 'size');
                        break;
                    }
                    // Use the value from the 'size' argument.
                    $size = strtolower($this->getRequest('size'));
                    if (!in_array($size, array('s', 'm', 'l'))) {
                        /**
                        * The value from the 'size' argument was not valid,
                        * use default size (medium) instead.
                        */
                        $size = 'm';
                    }
                } else {
                    // No specific size was requested, use default size (medium):
                    $size = 'm';
                }
                // Send the response.
                $this->sendResponse(array('avatar' => $user->getAvatar($size)));
                break;
            case 'getconversations':
                /**
                * Grabs the conversations from the specified user.
                * 
                * NOTE: The 'value' argument will only work for the user itself and
                *       not on others users unless the permission argument for the 
                *       'getconversations' action is changed (default permission: private).
                *
                * EXAMPLES: 
                *   - api.php?action=getConversations&hash=USERNAME:HASH
                *   - api.php?action=getConversations&value=USERNAME&hash=USERNAME:HASH
                *   - api.php?action=getConversations&value=USERNAME&hash=API_KEY
                */
                // Init variables.
                $conditions = array();
                $this->setLimit(10);
                $fetch_options = array('limit' => $this->limit, 'join' => XenForo_Model_Conversation::FETCH_FIRST_MESSAGE);

                // Grab the conversations.
                $conversations = $this->getXenAPI()->getConversations($user, $conditions, $fetch_options);

                // Send the response.
                $this->sendResponse(array('count' => count($conversations), 'conversations' => $conversations));
                break;
            case 'getgroup': 
                /**
                * Returns the group information depending on the 'value' argument.
                *
                * NOTE: Only group titles, user titles and group ID's can be used for the 'value' parameter.
                *
                * EXAMPLE:
                *   - api.php?action=getGroup&value=1
                *   - api.php?action=getGroup&value=Guest
                */
                if (!$this->hasRequest('value')) {
                    // The 'value' argument has not been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                } else if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                $string = $this->getRequest('value');
                
                // Get the group from XenForo.
                $group = $this->getXenAPI()->getGroup($string);

                if (!$group) {
                    // Could not find any groups, throw error.
                    $this->throwError(4, 'group', $string);
                } else {
                    // Group was found, send response.
                    $this->sendResponse($group);
                }
                break;
            case 'getnode':
                /**
                * Returns the node information depending on the 'value' argument.
                *
                * NOTE: Only node ID's can be used for the 'value' parameter.
                *       Node ID's can be found by using the 'getNodes' action.
                *
                *       The user needs permission to see the thread if the request is
                *       using a user hash and not an API key.
                *
                * EXAMPLE:
                *   - api.php?action=getNode&value=4&hash=USERNAME:HASH
                *   - api.php?action=getNode&value=4&hash=API_KEY
                */
                if (!$this->hasRequest('value')) {
                    // The 'value' argument has not been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                } else if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                $string = $this->getRequest('value');
                // Try to grab the node from XenForo.
                $node = $this->getXenAPI()->getNode($string);
                if ($node == NULL) {
                     // Could not find the node, throw error.
                    $this->throwError(19, 'node', $string);
                } else if (!$this->hasAPIKey() && !$this->getXenAPI()->canViewNode($this->getUser(), $node)) {
                    if (isset($this->grab_as)) {
                        // Thread was found but the 'grab_as' user is not permitted to view the node.
                        $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this node');
                    } else { 
                        // Thread was found but the user is not permitted to view the node.
                        $this->throwError(20, 'You do', 'this node');
                    }
                } else if ($this->hasAPIKey() && isset($this->grab_as) && !$this->getXenAPI()->canViewNode($this->getUser(), $node)) {
                    // Thread was found but the 'grab_as' user is not permitted to view the node.
                    $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this node');
                } else {
                     // Thread was found, and the request was permitted.
                    $this->sendResponse($node);
                }
                break;
            case 'getnodes':
                /**
                * Returns a list of nodes.
                *
                * EXAMPLES: 
                *   - api.php?action=getNodes&hash=USERNAME:HASH
                *   - api.php?action=getNodes&hash=API_KEY
                */
                // Init variables.
                $this->setLimit(10);
                $fetch_options = array('limit' => $this->limit);

                // Check if request has node_type.
                if ($this->hasRequest('node_type')) {
                    if (!$this->getRequest('node_type')) {
                        // Throw error if the 'node_type' argument is set but empty.
                        $this->throwError(1, 'node_type');
                    }

                    // Set the node_type.
                    $node_type = strtolower($this->getRequest('node_type'));

                    // Check if the node type that is set exists.
                    if (!in_array($node_type, $this->getXenAPI()->getNodeTypes()) && $node_type != 'all') {
                        // Node type could not be found in the node type list, throw error.
                        $this->throwError(23, $this->getRequest('node_type'), implode(', ', $this->getXenAPI()->getNodeTypes()));
                    }
                } else {
                    $node_type = 'all';
                }

                // Get the nodes.
                $nodes = $this->getXenAPI()->getNodes($node_type, $fetch_options, $this->getUser());

                // Send the response.
                $this->sendResponse(array('count' => count($nodes), 'nodes' => $nodes));
            case 'getpost':
                /**
                * Returns the post information depending on the 'value' argument.
                *
                * NOTE: Only post ID's can be used for the 'value' parameter.
                *       Post ID's can be found by using the 'getPosts' action.
                *
                *       The user needs permission to see the thread if the request is
                *       using a user hash and not an API key.
                *
                * EXAMPLE:
                *   - api.php?action=getPost&value=820&hash=USERNAME:HASH
                *   - api.php?action=getPost&value=820&hash=API_KEY
                */
                if (!$this->hasRequest('value')) {
                    // The 'value' argument has not been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                } else if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                $string = $this->getRequest('value');
                // Try to grab the post from XenForo.
                $post = $this->getXenAPI()->getPost($string, array('join' => XenForo_Model_Post::FETCH_FORUM));
                if ($post == NULL) {
                     // Could not find the post, throw error.
                    $this->throwError(19, 'post', $string);
                } else if (!$this->hasAPIKey() && !$this->getXenAPI()->canViewPost($this->getUser(), $post)) {
                    if (isset($this->grab_as)) {
                        // Post was found but the 'grab_as' user is not permitted to view the post.
                        $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this post');
                    } else { 
                        // Post was found but the user is not permitted to view the post.
                        $this->throwError(20, 'You do', 'this post');
                    }
                } else if ($this->hasAPIKey() && isset($this->grab_as) && !$this->getXenAPI()->canViewPost($this->getUser(), $post)) {
                    // Post was found but the 'grab_as' user is not permitted to view the post.
                    $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this post');
                } else {
                     // Post was found, and the request was permitted.
                    $this->sendResponse($post);
                }
                break;
            case 'getposts':
                /**
                * Returns a list of posts.
                *
                * NOTE: Only usernames and user ID's can be used for the 'author' parameter.
                *
                * EXAMPLES: 
                *   - api.php?action=getPosts&hash=USERNAME:HASH
                *   - api.php?action=getPosts&hash=API_KEY
                *   - api.php?action=getPosts&author=Contex&hash=USERNAME:HASH
                *   - api.php?action=getPosts&author=1&hash=API_KEY
                */
                // Init variables.
                $conditions = array();
                $this->setLimit(10);
                $fetch_options = array('limit' => $this->limit);

                // Check if request has author.
                if ($this->hasRequest('author')) {
                    if (!$this->getRequest('author')) {
                        // Throw error if the 'author' argument is set but empty.
                        $this->throwError(1, 'author');
                        break;
                    }
                    // Grab the user object of the author.
                    $user = $this->xenAPI->getUser($this->getRequest('author'));
                    if (!$user->isRegistered()) {
                        // Throw error if the 'author' user is not registered.
                        $this->throwError(4, 'user', $this->getRequest('author'));
                        break;
                    }
                    // Add the user ID to the query conditions.
                    $conditions['user_id'] = $user->getID();
                    unset($user);
                }

                // Check if request has node id.
                if ($this->hasRequest('node_id')) {
                    if (!$this->getRequest('node_id') && (is_numeric($this->getRequest('node_id')) && $this->getRequest('node_id') != 0)) {
                        // Throw error if the 'node_id' argument is set but empty.
                        $this->throwError(1, 'node_id');
                    } else if (!is_numeric($this->getRequest('node_id'))) {
                        // Throw error if the 'node_id' argument is set but not a number.
                        $this->throwError(21, 'node_id');
                    }
                    if (!$this->xenAPI->getNode($this->getRequest('node_id'))) {
                        // Could not find any nodes, throw error.
                        $this->throwError(4, 'node', $this->getRequest('node_id'));
                    }
                    // Add the node ID to the query conditions.
                    $conditions['node_id'] = $this->getRequest('node_id');
                }

                // Check if request has thread id.
                if ($this->hasRequest('thread_id')) {
                    if (!$this->getRequest('thread_id') && (is_numeric($this->getRequest('thread_id')) && $this->getRequest('thread_id') != 0)) {
                        // Throw error if the 'thread_id' argument is set but empty.
                        $this->throwError(1, 'thread_id');
                    } else if (!is_numeric($this->getRequest('thread_id'))) {
                        // Throw error if the 'thread_id' argument is set but not a number.
                        $this->throwError(21, 'thread_id');
                    }
                    if (!$this->xenAPI->getThread($this->getRequest('thread_id'))) {
                        // Could not find any threads, throw error.
                        $this->throwError(4, 'thread_id', $this->getRequest('thread_id'));
                    }
                    // Add the node ID to the query conditions.
                    $conditions['thread_id'] = $this->getRequest('thread_id');
                }

                // Check if the order by argument is set.
                $order_by_field = $this->checkOrderBy(array('post_id', 'thread_id', 'user_id', 'username', 'post_date', 'attach_count', 'likes', 'node_id'));

                // Add the order by options to the fetch options.
                if ($this->hasRequest('order_by')) {
                    $fetch_options['order']          = $order_by_field;
                    $fetch_options['orderDirection'] = $this->order;
                }

                // Get the posts.
                $posts = $this->getXenAPI()->getPosts($conditions, $fetch_options, $this->getUser());

                // Send the response.
                $this->sendResponse(array('count' => count($posts), 'posts' => $posts));
            case 'getprofilepost':
                /**
                * Returns the profile post information depending on the 'value' argument.
                *
                * NOTE: Only profile post ID's can be used for the 'value' parameter.
                *       Profile post ID's can be found by using the 'getProfilePosts' action.
                *
                *       The user needs permission to see the profile post if the request is
                *       using a user hash and not an API key.
                *
                * EXAMPLE:
                *   - api.php?action=getProfilePost&value=820&hash=USERNAME:HASH
                *   - api.php?action=getProfilePost&value=820&hash=API_KEY
                */
                if (!$this->hasRequest('value')) {
                    // The 'value' argument has not been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                } else if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                $string = $this->getRequest('value');
                // Try to grab the profile post from XenForo.
                $profile_post = $this->getXenAPI()->getProfilePost($string);
                if ($profile_post == NULL) {
                     // Could not find the profile post, throw error.
                    $this->throwError(19, 'profile post', $string);
                } else if (!$this->hasAPIKey() && !$this->getXenAPI()->canViewProfilePost($this->getUser(), $profile_post)) {
                    if (isset($this->grab_as)) {
                        // Thread was found but the 'grab_as' user is not permitted to view the thread.
                        $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this profile post');
                    } else { 
                        // Thread was found but the user is not permitted to view the profile post.
                        $this->throwError(20, 'You do', 'this profile post');
                    }
                } else if ($this->hasAPIKey() && isset($this->grab_as) && !$this->getXenAPI()->canViewProfilePost($this->getUser(), $profile_post)) {
                    // Thread was found but the 'grab_as' user is not permitted to view the profile post.
                    $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this profile post');
                } else {
                     // Post was found, and the request was permitted.
                    $this->sendResponse($profile_post);
                }
                break;
            case 'getprofileposts':
                /**
                * Returns a list of profile posts.
                *
                * NOTE: Only usernames and user ID's can be used for the 'author' parameter.
                *
                * EXAMPLES: 
                *   - api.php?action=getProfilePosts&hash=USERNAME:HASH
                *   - api.php?action=getProfilePosts&hash=API_KEY
                *   - api.php?action=getProfilePosts&author=Contex&hash=USERNAME:HASH
                *   - api.php?action=getProfilePosts&author=1&hash=API_KEY
                */
                // Init variables.
                $conditions = array();
                $this->setLimit(10);
                $fetch_options = array('limit' => $this->limit);

                // Check if request has author.
                if ($this->hasRequest('author')) {
                    if (!$this->getRequest('author')) {
                        // Throw error if the 'author' argument is set but empty.
                        $this->throwError(1, 'author');
                        break;
                    }
                    // Grab the user object of the author.
                    $user = $this->xenAPI->getUser($this->getRequest('author'));
                    if (!$user->isRegistered()) {
                        // Throw error if the 'author' user is not registered.
                        $this->throwError(4, 'user', $this->getRequest('author'));
                        break;
                    }
                    // Add the user ID to the query conditions.
                    $conditions['author_id'] = $user->getID();
                    unset($user);
                }

                // Check if request has profile user.
                if ($this->hasRequest('profile')) {
                    if (!$this->getRequest('profile')) {
                        // Throw error if the 'profile' argument is set but empty.
                        $this->throwError(1, 'profile');
                        break;
                    }
                    // Grab the user object of the profile.
                    $user = $this->xenAPI->getUser($this->getRequest('profile'));
                    if (!$user->isRegistered()) {
                        // Throw error if the 'author' user is not registered.
                        $this->throwError(4, 'user', $this->getRequest('profile'));
                        break;
                    }
                    // Add the user ID to the query conditions.
                    $conditions['profile_id'] = $user->getID();
                    unset($user);
                }

                // Check if the order by argument is set.
                $order_by_field = $this->checkOrderBy(array('profile_post_id', 'profile_user_id', 'user_id', 'username', 'post_date', 
                                                            'attach_count', 'likes', 'comment_count', 'first_comment_date', 
                                                            'last_comment_date'));

                // Add the order by options to the fetch options.
                if ($this->hasRequest('order_by')) {
                    $fetch_options['order']          = $order_by_field;
                    $fetch_options['orderDirection'] = $this->order;
                }

                // Get the profile posts.
                $profile_posts = $this->getXenAPI()->getProfilePosts($conditions, $fetch_options, $this->getUser());

                // Send the response.
                $this->sendResponse(array('count' => count($profile_posts), 'profile_posts' => $profile_posts));
            case 'getresource': 
                /**
                * Returns the resource information depending on the 'value' argument.
                *
                * NOTE: Only resource ID's can be used for the 'value' parameter.
                *       Resource ID's can be found by using the 'getResources' action.
                *
                * EXAMPLE:
                *   - api.php?action=getResource&value=1&hash=USERNAME:HASH
                *   - api.php?action=getResource&value=1&hash=API_KEY
                */
                if (!$this->getXenAPI()->getModels()->hasModel('resource')) {
                    $this->throwError(16, 'resource');
                    break;
                }
                if (!$this->hasRequest('value')) {
                    // The 'value' argument has not been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                } else if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                $string = $this->getRequest('value');
                // Try to grab the addon from XenForo.
                $resource = $this->getXenAPI()->getResource($string);
                if (!$resource->isValid()) {
                    // Could not find the resource, throw error.
                    $this->throwError(15, $string);
                } else {
                    // Resource was found, send response.
                    $this->sendResponse(Resource::getLimitedData($resource));
                }
                break;
            case 'getresources':
                /**
                * Returns a list of resources, either all the resources, 
                * or just the resources created by an author.
                *
                * NOTE: Only usernames and user ID's can be used for the 'author' parameter.
                *
                * EXAMPLES: 
                *   - api.php?action=getResources&hash=USERNAME:HASH
                *   - api.php?action=getResources&hash=API_KEY
                *   - api.php?action=getResources&author=Contex&hash=USERNAME:HASH
                *   - api.php?action=getResources&author=1&hash=API_KEY
                */
                /* 
                * Check if the request has the 'author' argument set, 
                * if it doesn't it uses the default (all).
                */
                if (!$this->getXenAPI()->getModels()->hasModel('resource')) {
                    $this->throwError(16, 'resource');
                    break;
                }
                if ($this->hasRequest('author')) {
                    if (!$this->getRequest('author')) {
                        // Throw error if the 'author' argument is set but empty.
                        $this->throwError(1, 'author');
                        break;
                    }
                    // Use the value from the 'author' argument to get the alerts.
                    $resources_list = $this->xenAPI->getResources($this->getRequest('author'));
                    if (count($resources_list) == 0) {
                       // Throw error if the 'author' is not the author of any resources.
                        $this->throwError(14, $this->getRequest('author'));
                        break;
                    }
                } else {
                    // Use the default type to get the alerts.
                    $resources_list = $this->getXenAPI()->getResources();
                }

                // Create an array for the resources.
                $resources = array();
                // Loop through all the resources and strip out any information that we don't need.
                foreach ($resources_list as $resource) {
                    $resources[] = Resource::getLimitedData($resource);
                }
                // Send the response.
                $this->sendResponse(array('count' => count($resources), 'resources' => $resources));
            case 'getstats':
                /**
                * Returns a summary of stats.
                *
                * EXAMPLE:
                *   - api.php?action=getStats
                */
                $latest_user = $this->xenAPI->getLatestUser();
                $this->sendResponse(array(
                    'threads'                => $this->xenAPI->getStatsItem('threads'),
                    'posts'                  => $this->xenAPI->getStatsItem('posts'),
                    'conversations'          => $this->xenAPI->getStatsItem('conversations'),
                    'conversations_messages' => $this->xenAPI->getStatsItem('conversations_messages'),
                    'members'                => $this->xenAPI->getStatsItem('users'),
                    'latest_member'          => array('user_id' => $latest_user->getID(), 'username' => $latest_user->getUsername()),
                    'registrations_today'    => $this->xenAPI->getStatsItem('registrations_today'),
                    'threads_today'          => $this->xenAPI->getStatsItem('threads_today'),
                    'posts_today'            => $this->xenAPI->getStatsItem('posts_today'),
                    'users_online'           => $this->xenAPI->getUsersOnlineCount($this->getUser())
                ));
                break;
            case 'getthread':
                /**
                * Returns the thread information depending on the 'value' argument.
                *
                * NOTE: Only thread ID's can be used for the 'value' parameter.
                *       Thread ID's can be found by using the 'getThreads' action.
                *
                *       The user needs permission to see the thread if the request is
                *       using a user hash and not an API key.
                *
                * EXAMPLE:
                *   - api.php?action=getThread&value=820&hash=USERNAME:HASH
                *   - api.php?action=getThread&value=820&hash=API_KEY
                */
                if (!$this->hasRequest('value')) {
                    // The 'value' argument has not been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                } else if (!$this->getRequest('value')) {
                    // Throw error if the 'value' argument is set but empty.
                    $this->throwError(1, 'value');
                    break;
                }
                $string = $this->getRequest('value');
                // Try to grab the thread from XenForo.
                $thread = $this->getXenAPI()->getThread($string);
                if ($thread == NULL) {
                     // Could not find the thread, throw error.
                    $this->throwError(19, 'thread', $string);
                } else if (!$this->hasAPIKey() && !$this->getXenAPI()->canViewThread($this->getUser(), $thread)) {
                    if (isset($this->grab_as)) {
                        // Thread was found but the 'grab_as' user is not permitted to view the thread.
                        $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this thread');
                    } else { 
                        // Thread was found but the user is not permitted to view the thread.
                        $this->throwError(20, 'You do', 'this thread');
                    }
                } else if ($this->hasAPIKey() && isset($this->grab_as) && !$this->getXenAPI()->canViewThread($this->getUser(), $thread)) {
                    // Thread was found but the 'grab_as' user is not permitted to view the thread.
                    $this->throwError(20, $this->getUser()->getUsername() . ' does', 'this thread');
                } else {
                     // Thread was found, and the request was permitted.
                    $this->sendResponse($thread);
                }
                break;
            case 'getthreads':
                /**
                * Returns a list of threads.
                *
                * NOTE: Only usernames and user ID's can be used for the 'author' parameter.
                *
                * EXAMPLES: 
                *   - api.php?action=getThreads&hash=USERNAME:HASH
                *   - api.php?action=getThreads&hash=API_KEY
                *   - api.php?action=getThreads&author=Contex&hash=USERNAME:HASH
                *   - api.php?action=getThreads&author=1&hash=API_KEY
                */
                // Init variables.
                $conditions = array();
                $this->setLimit(10);
                $fetch_options = array('limit' => $this->limit);

                // Check if request has author.
                if ($this->hasRequest('author')) {
                    if (!$this->getRequest('author')) {
                        // Throw error if the 'author' argument is set but empty.
                        $this->throwError(1, 'author');
                        break;
                    }
                    // Grab the user object of the author.
                    $user = $this->xenAPI->getUser($this->getRequest('author'));
                    if (!$user->isRegistered()) {
                        // Throw error if the 'author' user is not registered.
                        $this->throwError(4, 'user', $this->getRequest('author'));
                        break;
                    }
                    // Add the user ID to the query conditions.
                    $conditions['user_id'] = $user->getID();
                    unset($user);
                }

                // Check if request has author.
                if ($this->hasRequest('node_id')) {
                    if (!$this->getRequest('node_id') && (is_numeric($this->getRequest('node_id')) && $this->getRequest('node_id') != 0)) {
                        // Throw error if the 'node_id' argument is set but empty.
                        $this->throwError(1, 'node_id');
                    } else if (!is_numeric($this->getRequest('node_id'))) {
                        // Throw error if the 'limit' argument is set but not a number.
                        $this->throwError(21, 'node_id');
                    }
                    if (!$this->xenAPI->getNode($this->getRequest('node_id'))) {
                        // Could not find any nodes, throw error.
                        $this->throwError(4, 'node', $this->getRequest('node_id'));
                    }
                    // Add the node ID to the query conditions.
                    $conditions['node_id'] = $this->getRequest('node_id');
                }

                // Check if the order by argument is set.
                $order_by_field = $this->checkOrderBy(array('title', 'post_date', 'view_count', 'reply_count', 'first_post_likes', 'last_post_date'));

                // Add the order by options to the fetch options.
                if ($this->hasRequest('order_by')) {
                    $fetch_options['order']          = $order_by_field;
                    $fetch_options['orderDirection'] = $this->order;
                }

                // Get the threads.
                $threads = $this->getXenAPI()->getThreads($conditions, $fetch_options, $this->getUser());

                // Send the response.
                $this->sendResponse(array('count' => count($threads), 'threads' => $threads));
            case 'getuser': 
                /**
                * Grabs and returns an user object.
                * 
                * EXAMPLES: 
                *   - api.php?action=getUser&hash=USERNAME:HASH
                *   - api.php?action=getUser&value=USERNAME&hash=USERNAME:HASH
                *   - api.php?action=getUser&value=USERNAME&hash=API_KEY
                */
                $data = $user->getData();
                
                /*
                * Run through an additional permission check if the request is
                * not using an API key, unset some variables depending on the 
                * user level.
                */
                if (!$this->hasAPIKey()) {
                    // Unset variables since the API key isn't used.
                    if (isset($data['style_id'])) {
                        unset($data['style_id']);
                    }
                    if (isset($data['display_style_group_id'])) {
                        unset($data['display_style_group_id']);
                    }
                    if (isset($data['permission_combination_id'])) {
                        unset($data['permission_combination_id']);
                    }
                    if (!$this->getUser()->isAdmin()) {
                        // Unset variables if user is not an admin.
                        if (isset($data['is_banned'])) {
                            unset($data['is_banned']);
                        }
                    }
                    if (!$this->getUser()->isModerator()) {
                        // Unset variables if user is not a moderator.
                        if (isset($data['user_state'])) {
                            unset($data['user_state']);
                        }
                        if (isset($data['visible'])) {
                            unset($data['visible']);
                        }
                        if (isset($data['email'])) {
                            unset($data['email']);
                        }
                    } 
                    if ($this->getUser()->getID() != $user->getID()) {
                        // Unset variables if user does not equal the requested user by the 'value' argument.
                        if (isset($data['language_id'])) {
                            unset($data['language_id']);
                        }
                        if (isset($data['message_count'])) {
                            unset($data['message_count']);
                        }
                        if (isset($data['conversations_unread'])) {
                            unset($data['conversations_unread']);
                        }
                        if (isset($data['alerts_unread'])) {
                            unset($data['alerts_unread']);
                        }
                    }
                }
                // Send the response.
                $this->sendResponse($data);
                break;
            case 'getusers': 
                /**
                * Searches through the usernames depending on the input.
                *
                * NOTE: Asterisk (*) can be used as a wildcard.
                *
                * EXAMPLE:
                *   - api.php?action=getUsers&value=Contex
                *   - api.php?action=getUsers&value=Cont*
                *   - api.php?action=getUsers&value=C*
                */
                if ($this->hasRequest('value')) {
                    // Request has value.
                    if (!$this->getRequest('value')) {
                        // Throw error if the 'value' argument is set but empty.
                        $this->throwError(1, 'value');
                        break;
                    }
                    // Replace the wildcard with '%' for the SQL query.
                    $string = str_replace('*', '%', $this->getRequest('value'));
                } else if (!$this->hasRequest('order_by')) {
                    // Nor the 'value' argument or the 'order_by' argument has been set, throw error.
                    $this->throwError(3, 'value');
                    break;
                }

                // Check if the order by argument is set.
                $order_by_field = $this->checkOrderBy(array('user_id', 'message_count', 'conversations_unread', 'register_date', 'last_activity', 'trophy_points', 'alerts_unread', 'like_count'));
                
                // Perform the SQL query and grab all the usernames and user id's.
                $results = $this->xenAPI->getDatabase()->fetchAll("SELECT `user_id`, `username`" . ($this->hasRequest('order_by') ? ", `$order_by_field`" : '') . " FROM `xf_user`" . ($this->hasRequest('value') ? " WHERE `username` LIKE '$string'" : '') . ($this->hasRequest('order_by') ? " ORDER BY `$order_by_field` " . $this->order : '') . (($this->limit > 0) ? ' LIMIT ' . $this->limit : ''));
                
                // Send the response.
                $this->sendResponse($results);
                break;
            case 'register':
                /**
                * Registers a user.
                */
                // Init user array.
                $user_data = array();

                // Array of required parameters.
                $required_parameters = array('username', 'password', 'email');

                // Array of additional parameters.
                $additional_parameters = array('timezone', 'gender', 'dob_day', 'dob_month', 'dob_year', 'ip_address');

                foreach ($required_parameters as $required_parameter) {
                    // Check if the required parameter is set and not empty.
                    $this->checkRequestParameter($required_parameter);

                    // Set the request value.
                    $user_data[$required_parameter] = $this->getRequest($required_parameter);
                }

                foreach ($additional_parameters as $additional_parameter) {
                    // Check if the additional parameter is set and not empty.
                    $this->checkRequestParameter($additional_parameter, FALSE);

                    if ($this->getRequest($additional_parameter)) {
                        // Set the request value.
                        $user_data[$additional_parameter] = $this->getRequest($additional_parameter);
                    }
                }

                if ($this->hasRequest('group')) {
                    // Request has value.
                    if (!$this->getRequest('group')) {
                        // Throw error if the 'group' argument is set but empty.
                        $this->throwError(1, 'group');
                        break;
                    }
                    $group = $this->getXenAPI()->getGroup($this->getRequest('group'));
                    if (!$group) {
                        $registration_error = array(
                            'error_id' => 2,
                            'error_key' => 'group_not_found', 
                            'error_field' => 'group', 
                            'error_phrase' => 'Could not find group with parameter "' . $this->getRequest('group') . '"'
                        );
                        $this->throwError(self::USER_ERROR, $registration_error);
                    }
                    // Set the group id of the registration.
                    $user_data['group_id'] = $group['user_group_id'];
                }

                if ($this->hasRequest('custom_fields')) {
                    // Request has value.
                    if (!$this->getRequest('custom_fields')) {
                        // Throw error if the 'custom_fields' argument is set but empty.
                        $this->throwError(1, 'custom_fields');
                        break;
                    }
                    $custom_fields = $this->getCustomArray($this->getRequest('custom_fields'));

                    // Check if we found any valid custom fields, throw error if not.
                    if (count($custom_fields) == 0) {
                        // The custom fields array was empty, throw error.
                        $registration_error = array(
                            'error_id' => 5,
                            'error_key' => 'invalid_custom_fields', 
                            'error_field' => 'custom_fields', 
                            'error_phrase' => 'The custom fields values were invalid, valid values are: '
                                            . 'custom_fields=custom_field1=custom_value1,custom_field2=custom_value2 '
                                            . 'but got: "' . $this->getRequest('custom_fields') . '" instead'
                        );
                        $this->throwError(self::USER_ERROR, $registration_error);
                    }
                    $user_data['custom_fields'] = $custom_fields;
                }

                // Check if add groups is set.
                if ($this->hasRequest('add_groups')) {
                    // Request has value.
                    if (!$this->getRequest('add_groups')) {
                        // Throw error if the 'add_groups' argument is set but empty.
                        $this->throwError(1, 'add_groups');
                    }
                    // Initialize the array.
                    $user_data['add_groups'] = array();

                    // Check if value is an array.
                    if (strpos($this->getRequest('add_groups'), ',') !== FALSE) {
                        // Value is an array, explode it.
                        $groups = explode(',', $this->getRequest('add_groups'));

                        // Loop through the group values.
                        foreach ($groups as $group_value) {
                            // Grab the group from the group value.
                            $group = $this->getXenAPI()->getGroup($group_value);

                            // Check if group was found.
                            if (!$group) {
                                // Group was not found, throw error.
                                $edit_error = array(
                                    'error_id' => 2,
                                    'error_key' => 'group_not_found', 
                                    'error_field' => 'add_groups', 
                                    'error_phrase' => 'Could not find group with parameter "' . $group_value . '" in array "' . $this->getRequest('add_group') . '"'
                                );
                                $this->throwError(self::USER_ERROR, $edit_error);
                            }
                            // Add the group_id to the the add_group array.
                            $user_data['add_groups'][] = $group['user_group_id'];
                        }
                    } else {
                        // Grab the group from the group value.
                        $group = $this->getXenAPI()->getGroup($this->getRequest('add_groups'));

                        // Check if group was found.
                        if (!$group) {
                            // Group was not found, throw error.
                            $edit_error = array(
                                'error_id' => 2,
                                'error_key' => 'group_not_found', 
                                'error_field' => 'add_groups', 
                                'error_phrase' => 'Could not find group with parameter "' . $this->getRequest('add_groups') . '"'
                            );
                            $this->throwError(self::USER_ERROR, $edit_error);
                        }
                        // Add the group_id to the the add_groups array.
                        $user_data['add_groups'][] = $group['user_group_id'];
                    }
                }

                if ($this->hasRequest('user_state')) {
                    // Request has user_state.
                    if (!$this->getRequest('user_state')) {
                        // Throw error if the 'user_state' argument is set but empty.
                        $this->throwError(1, 'user_state');
                        break;
                    }
                    // Set the user state of the registration.
                    $user_data['user_state'] = $this->getRequest('user_state');
                }

                if ($this->hasRequest('language_id')) {
                    // Request has language_id.
                    if (!$this->getRequest('language_id')) {
                        // Throw error if the 'language_id' argument is set but empty.
                        $this->throwError(1, 'language_id');
                        break;
                    }
                    // Set the language id of the registration.
                    $user_data['language_id'] = $this->getRequest('language_id');
                }

                $registration_results = $this->getXenAPI()->register($user_data);

                if (!empty($registration_results['error'])) {
                    // The registration failed, process errors.
                    if (is_array($registration_results['errors'])) {
                        // The error message was an array, loop through the messages.
                        $error_keys = array();
                        foreach ($registration_results['errors'] as $error_field => $error) {
                            if (!($error instanceof XenForo_Phrase)) {
                                $registration_error = array(
                                    'error_id' => 1,
                                    'error_key' => 'field_not_recognised', 
                                    'error_field' => $error_field, 
                                    'error_phrase' => $error
                                );
                                $this->throwError(self::USER_ERROR, $registration_error);

                            }

                            // Let's init the registration error array.
                            $registration_error = array(
                                'error_id' => $this->getUserErrorID($error->getPhraseName()),
                                'error_key' => $error->getPhraseName(), 
                                'error_field' => $error_field, 
                                'error_phrase' => $error->render()
                            );
                            
                            $this->throwError(self::USER_ERROR, $registration_error);
                        }
                    } else {
                        $registration_error = array(
                            'error_id' => $registration_results['error'],
                            'error_key' => 'general_user_registration_error', 
                            'error_phrase' => $registration_results['errors']
                        );

                        $this->throwError(self::USER_ERROR, $registration_error);
                    }
                } else {
                    // Registration was successful, return results.
                    $this->sendResponse($registration_results);
                }
                break;
            default:
                // Action was supported but has not yet been added to the switch statement, throw error.
                $this->throwError(11, $this->getAction());
        }
        $this->throwError(7, 'executing action', $this->getAction());
    }
    
    /**
    * Send the response array in JSON.
    */
    public function sendResponse($data) {
        if ($this->hasRequest('performance')) {
    		global $time_start;
			$time_end = microtime(TRUE);
			$data['execution_time'] = $time_end - $time_start;
		}
        header('Content-type: application/json');
        die(json_encode($data));
    }
}

/**
* The XenAPI class provides all the functions and variables 
* that are needed to use XenForo's classes and functions.
*/
class XenAPI {
    private $xfDir, $models;
    
    /**
    * Default consturctor, instalizes XenForo classes and models.
    */
    public function __construct() {
        $this->xfDir = dirname(__FILE__);
        require_once($this->xfDir . '/library/XenForo/Autoloader.php');
        XenForo_Autoloader::getInstance()->setupAutoloader($this->xfDir. '/library');
        XenForo_Application::initialize($this->xfDir . '/library', $this->xfDir);
        XenForo_Application::set('page_start_time', microtime(TRUE));

        // Disable XenForo's PHP 
        XenForo_Application::disablePhpErrorHandler();

        // Enable error logging for PHP.
        error_reporting(E_ALL & ~E_NOTICE);
        $this->models = new Models();
        // TODO: Don't create models on init, only create them if they're being used (see Models::checkModel($model_name, $model)).
        $this->getModels()->setUserModel(XenForo_Model::create('XenForo_Model_User'));
        $this->getModels()->setAlertModel(XenForo_Model::create('XenForo_Model_Alert'));
        $this->getModels()->setUserFieldModel(XenForo_Model::create('XenForo_Model_UserField'));
        $this->getModels()->setAvatarModel(XenForo_Model::create('XenForo_Model_Avatar'));
        $this->getModels()->setModel('addon', XenForo_Model::create('XenForo_Model_AddOn'));
        $this->getModels()->setModel('database', XenForo_Application::get('db'));
        try {
            $this->getModels()->setModel('resource', XenForo_Model::create('XenResource_Model_Resource'));
        } catch (Exception $ignore) {
            // The resource model is missing, ignore the exceiption.
        }
    }

    public function editUser($user, $edit_data = array()) {
        if (!$user) {
            return array('error' => 3, 'errors' => 'The user array key was not set.');
        }
        if (!$user->isRegistered()) {
            return array('error' => 4, 'errors' => 'User is not registered.');
        }
        if (empty($user->data['dob_day'])) {
            // We need the full profile of the user, let's re-grab the user and get the full profile.
            $user = $this->getUser($user->getID(), array('join' => XenForo_Model_User::FETCH_USER_FULL));
        }
        $this->getModels()->checkModel('user', XenForo_Model::create('XenForo_Model_User'));
        // Check if user is super admin.
        if ($this->getModels()->getModel('user')->isUserSuperAdmin($user->data)) {
            // User is super admin, we do not allow editing super admins, return error.
            return array('error' => 6, 'errors' => 'Editing super admins is disabled.');
        }

        if (!empty($edit_data['password'])) {
            // Create a new variable for the password.
            $password = $edit_data['password'];

            // Unset the password from the user data array.
            unset($edit_data['password']);
        }

        // Init the diff array.
        $diff_array = array();

        // Create the data writer object for registrations, and set the defaults.
        $writer = XenForo_DataWriter::create('XenForo_DataWriter_User');

        // Set the existing data of the user before we submit the data.
        $writer->setExistingData($user->data);

        // Let the writer know that the edit is legit and made by an administrator.
        $writer->setOption(XenForo_DataWriter_User::OPTION_ADMIN_EDIT, TRUE);

        if (!empty($edit_data['group_id'])) {
            // Group ID is set.
            $writer->set('user_group_id', $edit_data['group_id']);

            // We need to unset the group id as we don't want it to be included into the bulk set.
            unset($edit_data['group_id']);
        }

        if (!empty($edit_data['remove_group_id'])) {
            // Group ID is set.
            #$writer->set('user_group_id', $edit_data['group_id']);

            // We need to unset the group id as we don't want it to be included into the bulk set.
            unset($edit_data['remove_group_id']);
        }
        if (!empty($edit_data['add_groups'])) {
            // Add group is set.

            // Check if there are any custom fields in the data array.
            if (!is_array($edit_data['add_groups']) || count($edit_data['add_groups']) == 0) {
                // The edit failed, return errors.
                return array('error' => 7, 'errors' => 'The add_groups parameter needs to be an array and have at least 1 item.');
            }

            // Initialize some arrays.
            $groups = array();
            $groups_exist = array();

            // Check if there are more than one custom array.
            if (strpos($user->data['secondary_group_ids'], ',') !== FALSE) {
                // Value is an array, explode it.
                $groups = explode(',', $user->data['secondary_group_ids']);
            } else {
                // Value is not an array, just add the single group  to the array.
                $groups[] = $user->data['secondary_group_ids'];
            }

            // Loop through the groups that are going to be added to check if the user already have the groups.
            foreach ($edit_data['add_groups'] as $group_id) {
                // Check if the user already is in the group.
                if (in_array($group_id, $groups)) {
                    // User is already in the group, add the group ID to the group_exist array.
                    $groups_exist[] = $group_id;
                } else {
                    // User is not in the group, add the group ID to the new_groups array.
                    $groups[] = $group_id;
                    $diff_array['new_secondary_groups'][] = $group_id;
                }
            }

            // Check if the user is in one or more of the specified groups.
            if (count($groups_exist) > 0) {
                // The user was already in one or more groups, return error.
                return array('error' => 8, 'errors' => 'The user is already a member of the group ID\'s: (' . implode(',', $groups_exist) . ')');
            }

            // Set the secondary group(s) of the user.
            $writer->setSecondaryGroups($groups);

            // We need to unset the group id as we don't want it to be included into the bulk set.
            unset($edit_data['add_groups']);
        }

        if (!empty($edit_data['remove_groups'])) {
            // Remove group is set.

            // Check if there are any custom fields in the data array.
            if (!is_array($edit_data['remove_groups']) || count($edit_data['remove_groups']) == 0) {
                // The edit failed, return errors.
                return array('error' => 11, 'errors' => 'The remove_groups parameter needs to be an array and have at least 1 item.');
            }

            // Initialize some arrays.
            $groups = array();
            $groups_not_exist = array();

            // Check if there are more than one custom array.
            if (strpos($user->data['secondary_group_ids'], ',') !== FALSE) {
                // Value is an array, explode it.
                $groups = explode(',', $user->data['secondary_group_ids']);
            } else {
                // Value is not an array, just add the single group to the array.
                $groups[] = $user->data['secondary_group_ids'];
            }

            // Loop through the groups that are going to be added to check if the user already have the groups.
            foreach ($edit_data['remove_groups'] as $group_key => $group_id) {
                // Check if the user already is in the group.
                if (!in_array($group_id, $groups) && $user->data['user_group_id'] != $group_id) {
                    // User is already in the group, add the group ID to the group_exist array.
                    $groups_not_exist[] = $group_id;
                } else {
                    // Check if user's primary group is the group ID.
                    if (!empty($user->data['user_group_id']) && $user->data['user_group_id'] == $group_id) {
                        // User's primary group ID was found in the remove_groups array, move the user to the default registration group.
                        $writer->set('user_group_id', XenForo_Model_User::$defaultRegisteredGroupId);
                         $diff_array['removed_group'] = $group_id;
                    } else {
                        // User is in the group, add the group ID to the remove_groups array.
                        $diff_array['removed_secondary_groups'][] = $group_id;
                    }
                    // Unset the group id.
                    unset($groups[$group_key]);
                }
            }

            // Check if the user is in one or more of the specified groups.
            if (count($groups_not_exist) > 0) {
                // The user was already in one or more groups, return error.
                return array('error' => 12, 'errors' => 'The user is not a member of group ID\'s: (' . implode(',', $groups_not_exist) . ')');
            }

            // Set the secondary group(s) of the user.
            $writer->setSecondaryGroups($groups);

            // We need to unset the group id as we don't want it to be included into the bulk set.
            unset($edit_data['remove_groups']);
        }

        if (!empty($edit_data['secondary_group_ids'])) {
            // Secondary group ID's are set.
            $writer->setSecondaryGroups(unserialize($edit_data['secondary_group_ids']));

            // We need to unset the secondary group id's as we don't want it to be included into the bulk set.
            unset($edit_data['secondary_group_ids']);
        }

        if (!empty($edit_data['custom_fields'])) {
            // Custom fields are set.

            // Check if there are any custom fields in the data array.
            if (count($edit_data['custom_fields']) > 0) {
                // There were one or more custom fields set, set them in the writer.
                $writer->setCustomFields($edit_data['custom_fields']);
            }
            // We need to unset the custom fields as we don't want it to be included into the bulk set.
            unset($edit_data['custom_fields']);
        }

        // Bulkset the edited data.
        $writer->bulkSet($edit_data);

        if (isset($password)) {
            // Set the password for the data writer.
            $writer->setPassword($password, $password);
        }

        // Set the data for the data writer.
        $writer->bulkSet($edit_data);

        // Pre save the data.
        $writer->preSave();

        if ($writer->hasErrors()) {
            // The edit failed, return errors.
            return array('error' => TRUE, 'errors' => $writer->getErrors());
        }

        // Save the user to the database.
        $writer->save();
         
        // Get the user data.
        $user_data = $writer->getMergedData();

        // Check the difference between the before and after data.
        $diff_array = array_merge(array_diff($user->data, $user_data), $diff_array);

        foreach ($diff_array as $diff_key => $diff_value) {
            if (isset($user_data[$diff_key])) {
                $diff_array[$diff_key] = $user_data[$diff_key];
            }
        }

        if (isset($diff_array['secondary_group_ids'])) {
            unset($diff_array['secondary_group_ids']);
        }

        if (!empty($diff_array['custom_fields'])) {
            // Check the difference in the custom fields.
            $custom_fields_diff_array = array_diff(unserialize($user->data['custom_fields']), unserialize($diff_array['custom_fields']));

            unset($diff_array['custom_fields']);

            // Loop through the differences and add them to the diff array.
            foreach ($custom_fields_diff_array as $custom_fields_diff_key => $custom_fields_diff_value) {
                $diff_array['custom_fields'][$custom_fields_diff_key] = $custom_fields_diff_value;
            }
        }

        if (isset($password)) {
            // Password is changed, make sure we add it to the difference array.
            $diff_array['password'] = 'OK';
        }

        if (count($diff_array) == 0) {
            // Nothing was changed, throw error.
            return array('error' => 9, 'errors' => 'No values were changed.');
        }

        return $diff_array;
    }
    
    /**
    * Returns the Database model.
    */
    public function getDatabase() {
        return $this->getModels()->getModel('database');
    }
    
    /**
    * Returns the array of all the models.
    */
    public function getModels() {
        return $this->models;
    }
    
    /**
    * Grabs the User class of the last registered user.
    */
    public function getLatestUser() {
        return new User($this->getModels(), $this->getModels()->getUserModel()->getLatestUser());
    }
    
    /**
    * Returns the total count of registered users on XenForo.
    */
    public function getUserCount() {
        return $this->getModels()->getUserModel()->countTotalUsers();
    }

    /**
    * Returns a list of addons in the Addon class.
    */
    public function getAddons($type = 'all') {
        // TODO: add support to grab addon options.
        $type = strtolower($type);
        $allowed_types = array('all', 'enabled', 'disabled');
        if (!in_array($type, $allowed_types)) {
            $type = 'all';
        }
        $installed_addons = $this->getModels()->getModel('addon')->getAllAddOns();
        $addons = array();
        foreach ($installed_addons as $addon) {
            $temp_addon = new Addon($addon);
            if (($type == 'enabled' && $temp_addon->isEnabled()) || ($type == 'disabled' && !$temp_addon->isEnabled()) || $type == 'all') {
                $addons[] = $temp_addon;
            }
        }
        return $addons;
    }

    /**
    * Returns the Addon class of the $addon parameter.
    */
    public function getAddon($addon) {
        return new Addon($this->getModels()->getModel('addon')->getAddOnById($addon));
    }


    /**
    * Returns all the conversations of the user.
    */
    public function getConversations($user, $conditions = array(), $fetchOptions = array()) {
        $this->getModels()->checkModel('conversation', XenForo_Model::create('XenForo_Model_Conversation'));
        return $this->getModels()->getModel('conversation')->getConversationsForUser($user->getID(), $conditions, $fetchOptions);
    }

    public function getGroup($group) {
        // Get the group from the database.
        return $this->getDatabase()->fetchRow("SELECT * FROM `xf_user_group` WHERE `user_group_id` = '$group' OR `title` = '$group' OR `user_title` = '$group'");
    }

    /**
    * Returns a list of resources.
    */
    public function getResources($author = NULL) {
        $resources_list = $this->getModels()->getModel('resource')->getResources();
        $resources = array();
        foreach ($resources_list as $resource) {
            $temp_resource = new Resource($resource);
            if ($author != NULL 
                && (((is_numeric($author) && $temp_resource->getAuthorUserID() != $author) 
                    || strtolower($temp_resource->getAuthorUsername()) != strtolower($author)))) {
                // The author input is not NULL and the resource is not owned by the author, skip the resource.
                continue;
            }
            $resources[] = $temp_resource;
        }
        return $resources;
    }

    /**
    * Returns the Resource class of the $resource parameter.
    */
    public function getResource($resource) {
        return new Resource($this->getModels()->getModel('resource')->getResourceById($resource));
    }

    /**
    * TODO
    */
    public function getStats($start = NULL, $end = NULL, $types = NULL) {
        $this->getModels()->checkModel('stats', XenForo_Model::create('XenForo_Model_Stats'));
        // TODO
        return $this->getModels()->getModel('stats')->getStatsData(time() - 5000, time());
    }

    public function getStatsItem($item) {
        $this->getModels()->checkModel('database', XenForo_Application::get('db'));
        switch ($item) {
            case 'users':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_user');
            case 'conversations':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_conversation_master');
            case 'conversations_messages':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_conversation_message');
            case 'posts':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_post');
            case 'threads':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_thread');
            case 'registrations_today':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_user WHERE register_date > UNIX_TIMESTAMP(CURDATE())');
            case 'posts_today':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_post WHERE post_date > UNIX_TIMESTAMP(CURDATE()) AND position != 0');
            case 'threads_today':
                return $this->getModels()->getModel('database')->fetchOne('SELECT COUNT(*) FROM xf_thread WHERE post_date > UNIX_TIMESTAMP(CURDATE())');
            default:
                return NULL;
        }
    }

    /**
    * TODO
    */
    public function checkUserPermissions(&$user) {
        if ($user != NULL) {
            $this->getModels()->checkModel('user', XenForo_Model::create('XenForo_Model_User'));

            if (empty($user->data['global_permission_cache'])) {
                // Check if the user data has permissions cache set, grab it if not.
                $user = $this->getUser($user->getID(), array('join' => XenForo_Model_User::FETCH_USER_PERMISSIONS));
            }

            if (empty($user->data['permissions'])) {
                // Check if the user data has the permissions set, set it if not.
                $user->data['permissions'] = XenForo_Permission::unserializePermissions($user->data['global_permission_cache']);
                // Unset the permissions serialized cache as we don't need it anymore.
                unset($user->data['global_permission_cache']);
            }
        }
    }

    /**
    * TODO
    */
    public function getUsersOnlineCount($user = NULL) {
        $this->getModels()->checkModel('session', XenForo_Model::create('XenForo_Model_Session'));
        if ($user != NULL) {
            // User parameter is not null, make sure to follow privacy of the users.
            $this->getModels()->checkModel('user', XenForo_Model::create('XenForo_Model_User'));

            // Check user permissions.
            $this->checkUserPermissions($user);

            // Check if the user can bypass user privacy.
            $bypass = $this->getModels()->getModel('user')->canBypassUserPrivacy($null, $user->getData());
            $conditions = array(
                'cutOff' => array('>', $this->getModels()->getModel('session')->getOnlineStatusTimeout()),
                'getInvisible' => $bypass,
                'getUnconfirmed' => $bypass,
                'forceInclude' => ($bypass ? FALSE : $user->getID())
            );
        } else {
            // User parameter is null, ignore privacy and grab all the users.
            $conditions = array(
                'cutOff' => array('>', $this->getModels()->getModel('session')->getOnlineStatusTimeout())
            );
        }
        // Return the count of online visitors (users + guests).
        return $this->getModels()->getModel('session')->countSessionActivityRecords($conditions);
    }

    /**
    * Returns the Node array of the $node_id parameter.
    */
    public function getForum($node_id, $fetchOptions = array()) {
        $this->getModels()->checkModel('forum', XenForo_Model::create('XenForo_Model_Forum'));
        return $this->getModels()->getModel('forum')->getForumById($node_id, $fetchOptions);
    }

    /**
    * Returns the Link Forum array of the $node_id parameter.
    */
    public function getLinkForum($node_id, $fetchOptions = array()) {
        $this->getModels()->checkModel('link_forum', XenForo_Model::create('XenForo_Model_LinkForum'));
        return $this->getModels()->getModel('link_forum')->getLinkForumById($node_id, $fetchOptions);
    }


    /**
    * Returns the Node array of the $node_id parameter.
    */
    public function getNode($node_id, $fetchOptions = array()) {
        $this->getModels()->checkModel('node', XenForo_Model::create('XenForo_Model_Node'));
        $node = $this->getModels()->getModel('node')->getNodeById($node_id, $fetchOptions);
        if (!empty($node['node_type_id'])) {
            switch (strtolower($node['node_type_id'])) {
                case 'forum':
                    return $this->getForum($node['node_id'], $fetchOptions);
                case 'linkforum':
                    return $this->getLinkForum($node['node_id'], $fetchOptions);
                case 'page':
                    return $this->getPage($node['node_id'], $fetchOptions);
                case 'category':
                default:
                    return $node;
            }
        }
        return $node;
    }

    /**
    * Returns a list of nodes.
    */
    public function getNodes($node_type = 'all', $fetchOptions = array('limit' => 10), $user = NULL) {
        $this->getModels()->checkModel('node', XenForo_Model::create('XenForo_Model_Node'));

        // Get the node list.
        $node_list = $this->getModels()->getModel('node')->getAllNodes();

        // Check if the node type that is set exists.
        if ($node_type == NULL || !in_array($node_type, $this->getNodeTypes())) {
            $node_type = 'all';
        }
        
        // Loop through the nodes to check if the user has permissions to view the thread.
        foreach ($node_list as $key => &$node) {      
            if ($node_type != 'all' && strtolower($node['node_type_id']) != $node_type) {
                // Node type does not equal the requested node type, unset the node and continue the loop.
                unset($node_list[$key]);
                continue;
            }

            // Check if user is set.
            if ($user != NULL) {
                // Get the node.
                $node = $this->getNode($node['node_id'], array_merge($fetchOptions, array('permissionCombinationId' => $user->data['permission_combination_id'])));
                $permissions = XenForo_Permission::unserializePermissions($node['node_permission_cache']);

                // User does not have permission to view this nodes, unset it and continue the loop.
                if (!$this->canViewNode($user, $node, $permissions)) {
                    unset($node_list[$key]);
                    continue;
                }

                // Unset the permissions values.
                unset($node_list[$key]['node_permission_cache']);
            } else {
                // Get the node.
                $node = $this->getNode($node['node_id'], $fetchOptions);
            }
        }
        return $node_list;
    }

    /**
    * TODO
    */
    public function getNodeTypes() {
        $this->getModels()->checkModel('node', XenForo_Model::create('XenForo_Model_Node'));
        return array_keys(array_change_key_case($this->getModels()->getModel('node')->getAllNodeTypes(), CASE_LOWER));
    }

    /**
    * Returns the Page array of the $node_id parameter.
    */
    public function getPage($node_id, $fetchOptions = array()) {
        $this->getModels()->checkModel('page', XenForo_Model::create('XenForo_Model_Page'));
        return $this->getModels()->getModel('page')->getPageById($node_id, $fetchOptions);
    }

    /**
    * TODO
    */
    public function canViewNode($user, $node, $permissions = NULL) {
        // Check if the forum model has initialized.
        if (!empty($node['node_type_id'])) {
            if ($permissions == NULL) {
                // Let's grab the permissions.
                $node = $this->getNode($node['node_id'], array('permissionCombinationId' => $user->data['permission_combination_id']));

                // Unserialize the permissions.
                $permissions = XenForo_Permission::unserializePermissions($node['node_permission_cache']);
            }
            switch (strtolower($node['node_type_id'])) {
                case 'category':
                    $this->getModels()->checkModel('category', XenForo_Model::create('XenForo_Model_Category'));
                    return $this->getModels()->getModel('category')->canViewCategory($node, $null, $permissions, $user->getData());
                case 'forum':
                    $this->getModels()->checkModel('forum', XenForo_Model::create('XenForo_Model_Forum'));
                    return $this->getModels()->getModel('forum')->canViewForum($node, $null, $permissions, $user->getData());
                case 'linkforum':
                    $this->getModels()->checkModel('link_forum', XenForo_Model::create('XenForo_Model_LinkForum'));
                    return $this->getModels()->getModel('link_forum')->canViewLinkForum($node, $null, $permissions, $user->getData());
                case 'page':
                    $this->getModels()->checkModel('page', XenForo_Model::create('XenForo_Model_Page'));
                    return $this->getModels()->getModel('page')->canViewPage($node, $null, $permissions, $user->getData());
            }
        }
        return FALSE;
    }

    /**
    * Returns the Post array of the $post_id parameter.
    */
    public function getPost($post_id, $fetchOptions = array()) {
        $this->getModels()->checkModel('post', XenForo_Model::create('XenForo_Model_Post'));
        $post = $this->getModels()->getModel('post')->getPostById($post_id, $fetchOptions);
        if (!empty($fetchOptions['join'])) {
            // Unset the thread values.
            Post::stripThreadValues($post);
        }
        return $post;
    }

    /**
    * Returns a list of posts.
    */
    public function getPosts($conditions = array(), $fetchOptions = array('limit' => 10), $user = NULL) {
        if (!empty($conditions['node_id']) || (!empty($fetchOptions['order']) && strtolower($fetchOptions['order']) == 'node_id')) {
            // We need to grab the thread info to get the node_id.
            $fetchOptions = array_merge($fetchOptions, array('join' => XenForo_Model_Post::FETCH_THREAD));
        }
        $this->getModels()->checkModel('post', XenForo_Model::create('XenForo_Model_Post'));
        if ($user != NULL) {
            // User is set, we need to include permissions.
            if (!isset($fetchOptions['join'])) {
                // WE need to grab the thread to get the node permissions.
                $fetchOptions = array_merge($fetchOptions, array('join' => XenForo_Model_Post::FETCH_THREAD));
            }
            // User is set, we therefore have to grab the permissions to check if the user is allowed to view the post.
            $fetchOptions = array_merge($fetchOptions, array('permissionCombinationId' => $user->data['permission_combination_id']));
        }
        // Prepare query conditions.
        $whereConditions = Post::preparePostConditions($this->getModels()->getModel('database'), $this->getModels()->getModel('post'), $conditions);
        $sqlClauses = $this->getModels()->getModel('post')->preparePostJoinOptions($fetchOptions);
        $limitOptions = $this->getModels()->getModel('post')->prepareLimitFetchOptions($fetchOptions);

        // Since the Post model of XenForo does not have order by implemented, we have to do it ourselves.
        if (!empty($fetchOptions['order'])) {
            $orderBySecondary = '';
            switch ($fetchOptions['order']) {
                case 'post_id':
                case 'thread_id':
                case 'user_id':
                case 'username':
                case 'attach_count':
                case 'likes':
                    $orderBy = 'post.' . $fetchOptions['order'];
                    break;
                case 'node_id':
                    $orderBy = 'thread.' . $fetchOptions['order'];
                    break;
                case 'post_date':
                default:
                    $orderBy = 'post.post_date';
            }
            // Check if order direction is set.
            if (!isset($fetchOptions['orderDirection']) || $fetchOptions['orderDirection'] == 'desc') {
                $orderBy .= ' DESC';
            } else {
                $orderBy .= ' ASC';
            }
            $orderBy .= $orderBySecondary;
        }
        $sqlClauses['orderClause'] = (isset($orderBy) ? "ORDER BY $orderBy" : '');

        // Execute the query and get the result.
        $post_list = $this->getModels()->getModel('post')->fetchAllKeyed($this->getModels()->getModel('post')->limitQueryResults(
            '
                SELECT post.*
                    ' . $sqlClauses['selectFields'] . '
                FROM xf_post AS post ' . $sqlClauses['joinTables'] . '
                WHERE ' . $whereConditions . '
                ' . $sqlClauses['orderClause'] . '
            ', $limitOptions['limit'], $limitOptions['offset']
        ), 'post_id');

        // Loop through the posts to unset some values that are not needed.
        foreach ($post_list as $key => $post) {
            if ($user != NULL) {
                // Check if the user has permissions to view the post.
                $permissions = XenForo_Permission::unserializePermissions($post['node_permission_cache']);
                if (!$this->getModels()->getModel('post')->canViewPost($post, array('node_id' => $post['node_id']), array(), $null, $permissions, $user->getData())) {
                    // User does not have permission to view this post, unset it and continue the loop.
                    unset($post_list[$key]);
                    continue;
                }
                // Unset the permissions values.
                unset($post_list[$key]['node_permission_cache']);
            }

            if (isset($fetchOptions['join'])) {
                // Unset some not needed thread values.
                Post::stripThreadValues($post_list[$key]);
            }
        }
        return $post_list;
    }

    /**
    * Check if user has permissions to view post.
    */
    public function canViewPost($user, $post, $permissions = NULL) {
        // Check if the post model has initialized.
        $this->getModels()->checkModel('post', XenForo_Model::create('XenForo_Model_Post'));
        if ($permissions == NULL) {
            // Let's grab the permissions.
            $post = $this->getPost($post['post_id'], array(
                'permissionCombinationId' => $user->data['permission_combination_id'],
                'join' => XenForo_Model_Post::FETCH_FORUM
            ));

            // Unserialize the permissions.
            $permissions = XenForo_Permission::unserializePermissions($post['node_permission_cache']);
        }
        return $this->getModels()->getModel('post')->canViewPost($post, array('node_id' => $post['node_id']), array(), $null, $permissions, $user->getData());
    }

    /**
    * Returns the Post array of the $post_id parameter.
    */
    public function getProfilePost($profile_post_id, $fetchOptions = array()) {
        $this->getModels()->checkModel('profile_post', XenForo_Model::create('XenForo_Model_ProfilePost'));
        return $this->getModels()->getModel('profile_post')->getProfilePostById($profile_post_id, $fetchOptions);
    }

    /**
    * Returns a list of profile posts.
    */
    public function getProfilePosts($conditions = array(), $fetchOptions = array('limit' => 10), $user = NULL) {
        $this->getModels()->checkModel('profile_post', XenForo_Model::create('XenForo_Model_ProfilePost'));
        if ($user != NULL) {
            // User is set, we need to include permissions.
            $this->checkUserPermissions($user);
        }

        // Default the sql condition.
        $sqlConditions = array();

        if (count($conditions) > 0) {
            // We need to make our own check for these conditions as XenForo's functions doesn't fully support what we want.

            // Check if the author id is set.
            if (!empty($conditions['author_id'])) {
                $sqlConditions[] = "profile_post.user_id = " . $this->getModels()->getModel('database')->quote($conditions['author_id']);
            }

            // Check if the profile id is set.
            if (!empty($conditions['profile_id'])) {
                $sqlConditions[] = "profile_post.profile_user_id = " . $this->getModels()->getModel('database')->quote($conditions['profile_id']);
            }
        }

        // Use the model function to get conditions for clause from the sql conditions.
        $whereConditions = $this->getModels()->getModel('profile_post')->getConditionsForClause($sqlConditions);

        // Prepare query conditions.
        $sqlClauses = $this->getModels()->getModel('profile_post')->prepareProfilePostFetchOptions($fetchOptions);
        $limitOptions = $this->getModels()->getModel('profile_post')->prepareLimitFetchOptions($fetchOptions);

        // Since the profile post model of XenForo does not have order by implemented, we have to do it ourselves.
        if (!empty($fetchOptions['order'])) {
            $orderBySecondary = '';
            switch ($fetchOptions['order']) {
                case 'profile_post_id':
                case 'profile_user_id':
                case 'user_id':
                case 'username':
                case 'attach_count':
                case 'likes':
                case 'comment_count':
                case 'first_comment_date':
                case 'last_comment_date':
                    $orderBy = 'profile_post.' . $fetchOptions['order'];
                    break;
                case 'post_date':
                default:
                    $orderBy = 'profile_post.post_date';
            }
            // Check if order direction is set.
            if (!isset($fetchOptions['orderDirection']) || $fetchOptions['orderDirection'] == 'desc') {
                $orderBy .= ' DESC';
            } else {
                $orderBy .= ' ASC';
            }
            $orderBy .= $orderBySecondary;
        }
        $sqlClauses['orderClause'] = (isset($orderBy) ? "ORDER BY $orderBy" : '');

        // Execute the query and get the result.
        $profile_post_list = $this->getModels()->getModel('profile_post')->fetchAllKeyed($this->getModels()->getModel('profile_post')->limitQueryResults(
            '
                SELECT profile_post.*
                    ' . $sqlClauses['selectFields'] . '
                FROM xf_profile_post AS profile_post ' . $sqlClauses['joinTables'] . '
                WHERE ' . $whereConditions . '
                ' . $sqlClauses['orderClause'] . '
            ', $limitOptions['limit'], $limitOptions['offset']
        ), 'profile_post_id');

        if ($user != NULL) {
            // Loop through the profile posts to check permissions
            foreach ($profile_post_list as $key => $profile_post) {
                // Check if the user has permissions to view the profile post.
                if (!$this->getModels()->getModel('profile_post')->canViewProfilePost($profile_post, array(), $null, $user->getData())) {
                    // User does not have permission to view this profile post, unset it and continue the loop.
                    unset($profile_post_list[$key]);
                }
            }
        }

        // Return the profile post list.
        return $profile_post_list;
    }

    /**
    * Check if user has permissions to view post.
    */
    public function canViewProfilePost($user, $profile_post, $permissions = NULL) {
        // Check if the profile post model has initialized.
        $this->getModels()->checkModel('profile_post', XenForo_Model::create('XenForo_Model_ProfilePost'));

        // Check if the user object has the permissions data.
        $this->checkUserPermissions($user);

        // Return if the user has permissions to view the profile post.
        return $user != NULL && $this->getModels()->getModel('profile_post')->canViewProfilePost($profile_post, array(), $null, $user->getData());
    }



    /**
    * Returns the Thread array of the $thread_id parameter.
    */
    public function getThread($thread_id, $fetch_options = array()) {
        $this->getModels()->checkModel('thread', XenForo_Model::create('XenForo_Model_Thread'));
        return $this->getModels()->getModel('thread')->getThreadById($thread_id, $fetch_options);
    }

    /**
    * Returns a list of threads.
    */
    public function getThreads($conditions = array(), $fetchOptions = array('limit' => 10), $user = NULL) {
        $this->getModels()->checkModel('thread', XenForo_Model::create('XenForo_Model_Thread'));
        if ($user == NULL) {
            $thread_list = $this->getModels()->getModel('thread')->getThreads($conditions, $fetchOptions);
            return $thread_list;
        }
        $thread_list = $this->getModels()->getModel('thread')->getThreads($conditions, array_merge($fetchOptions, array('permissionCombinationId' => $user->data['permission_combination_id'])));
        // Loop through the threads to check if the user has permissions to view the thread.
        foreach ($thread_list as $key => $thread) {
            $permissions = XenForo_Permission::unserializePermissions($thread['node_permission_cache']);
            if (!$this->getModels()->getModel('thread')->canViewThread($thread, array(), $null, $permissions, $user->getData())) {
                // User does not have permission to view this thread, unset it and continue the loop.
                unset($thread_list[$key]);
            }
            // Unset the permissions values.
            unset($thread_list[$key]['node_permission_cache']);
        }
        return $thread_list;
    }


    /**
    * Returns the Thread array of the $thread_id parameter.
    */
    public function canViewThread($user, $thread, $permissions = NULL) {
        // Check if the thread model has initialized.
        $this->getModels()->checkModel('thread', XenForo_Model::create('XenForo_Model_Thread'));
        if ($permissions == NULL) {
            // Let's grab the permissions.
            $thread = $this->getThread($thread['thread_id'], array('permissionCombinationId' => $user->data['permission_combination_id']));

            // Unserialize the permissions.
            $permissions = XenForo_Permission::unserializePermissions($thread['node_permission_cache']);
        }
        return $this->getModels()->getModel('thread')->canViewThread($thread, array(), $null, $permissions, $user->getData());
    }
    
    /**
    * Returns the User class of the $input parameter.
    *
    * The $input parameter can be an user ID, username or e-mail.
    * Returns FALSE if $input is NULL.
    */
    public function getUser($input, $fetchOptions = array()) {
        if (!empty($fetchOptions['custom_field'])) {
            $results = $this->getDatabase()->fetchRow("SELECT `user_id` FROM `xf_user_field_value` WHERE `field_id` = '" . $fetchOptions['custom_field'] . "' AND `field_value` = '$input'");
            if (!empty($results['user_id'])) {
                $input = $results['user_id'];
            }
        }
        if ($input == FALSE || $input == NULL) {
            return FALSE;
        } else if (is_numeric($input)) {
            // $input is a number, grab the user by an ID.
            $user = new User($this->models, $this->models->getUserModel()->getUserById($input, $fetchOptions));
            if (!$user->isRegistered()) {
                // The user ID was not found, grabbing the user by the username instead.
                return new User($this->models, $this->models->getUserModel()->getUserByName($input, $fetchOptions));
            }
            return $user;
        } else if ($this->models->getUserModel()->couldBeEmail($input)) {
            // $input is an e-mail, return the user of the e-mail.
            return new User($this->models, $this->models->getUserModel()->getUserByEmail($input, $fetchOptions));
        } else {
            // $input is an username, return the user of the username.
            return new User($this->models, $this->models->getUserModel()->getUserByName($input, $fetchOptions));
        }
    }

    /**
    * TODO
    */
    public function register($user_data) {
        if (empty($user_data['username'])) {
            // Username was empty, return error.
            return array('error' => 10, 'errors' => 'Missing required parameter: username');
        } else if (empty($user_data['password'])) {
            // Password was empty, return error.
            return array('error' => 10, 'errors' => 'Missing required parameter: password');
        } else if (empty($user_data['email'])) {
            // Email was empty, return error.
            return array('error' => 10, 'errors' => 'Missing required parameter: email');
        }

        // Create a new variable for the password.
        $password = $user_data['password'];

        // Unset the password from the user data array.
        unset($user_data['password']);

        if (!empty($user_data['ip_address'])) {
            // Create a new variable for the ip address.
            $ip_address = $user_data['ip_address'];

            // Unset the ip address from the user data array.
            unset($user_data['ip_address']);
        }

        // Get the default options from XenForo.
        $options = XenForo_Application::get('options');

        // Create the data writer object for registrations, and set the defaults.
        $writer = XenForo_DataWriter::create('XenForo_DataWriter_User');
        if ($options->registrationDefaults) {
            // Set the default registration options if it's set in the XenForo options.
            $writer->bulkSet($options->registrationDefaults, array('ignoreInvalidFields' => TRUE));
        }

        if (!empty($user_data['group_id'])) {
            // Group ID is set.
            $writer->set('user_group_id', $user_data['group_id']);

            // We need to unset the group id as we don't want it to be included into the bulk set.
            unset($user_data['group_id']);
        } else {
            // Group ID is not set, default back to default.
            $writer->set('user_group_id', XenForo_Model_User::$defaultRegisteredGroupId);
        }

        if (!empty($user_data['user_state'])) {
            // User state is set.
            $writer->set('user_state', $user_data['user_state']);
        } else {
            // User state is not set, default back to default.
            $writer->advanceRegistrationUserState();
        }

        if (!empty($user_data['language_id'])) {
            // Language ID is set.
            $writer->set('language_id', $user_data['language_id']);
        } else {
            // Language ID is not set, default back to default.
            $writer->set('language_id', $options->defaultLanguageId);
        }

        if (!empty($user_data['custom_fields'])) {
            // Custom fields are set.

            // Check if there are any custom fields in the data array.
            if (count($user_data['custom_fields']) > 0) {
                // There were one or more custom fields set, set them in the writer.
                $writer->setCustomFields($user_data['custom_fields']);
            }
            // We need to unset the custom fields as we don't want it to be included into the bulk set.
            unset($user_data['custom_fields']);
        }

        if (!empty($user_data['add_groups'])) {
            // Add group is set.

            // Check if there are any custom fields in the data array.
            if (!is_array($user_data['add_groups']) || count($user_data['add_groups']) == 0) {
                // The edit failed, return errors.
                return array('error' => 7, 'errors' => 'The add_groups parameter needs to be an array and have at least 1 item.');
            }

            // Set the secondary group(s) of the user.
            $writer->setSecondaryGroups($user_data['add_groups']);

            // We need to unset the group id as we don't want it to be included into the bulk set.
            unset($user_data['add_groups']);
        }

        // Check if Gravatar is enabled, set the gravatar if it is and there's a gravatar for the email.
        if ($options->gravatarEnable && XenForo_Model_Avatar::gravatarExists($data['email'])) {
            $writer->set('gravatar', $user_data['email']);
        }

        // Set the data for the data writer.
        $writer->bulkSet($user_data);

        // Set the password for the data writer.
        $writer->setPassword($password, $password);

        // Pre save the data.
        $writer->preSave();

        if ($writer->hasErrors()) {
            // The registration failed, return errors.
            return array('error' => TRUE, 'errors' => $writer->getErrors());
        }

        // Save the user to the database.
        $writer->save();
         
        // Get the User as a variable:
        $user = $writer->getMergedData();

        // Check if IP is set.
        if (!empty($user_data['ip_address'])) {
            // Log the IP of the user that registered.
            XenForo_Model_Ip::log($user['user_id'], 'user', $user['user_id'], 'register', $ip_address);
        }

        // Send confirmation email
        if ($user['user_state'] == 'email_confirm') {
            XenForo_Model::create('XenForo_Model_UserConfirmation')->sendEmailConfirmation($user);
        }
         
        return $user;
    }
}

/**
* This class contains all the required models of XenForo.
*/
class Models {
    private $models = array();

    /**
    * Returns TRUE if the model exists, FALSE if not.
    */
    public function hasModel($model_name) {
        return isset($this->models[$model_name]) && $this->models[$model_name] != NULL;
    }

    /**
    * Checks if the model exists, adds it to the array if not.
    */
    public function checkModel($model_name, $model) {
        if (!$this->hasModel($model_name)) {
            $this->setModel($model_name, $model);
        }
    }

    /**
    * Returns the array of all the models. 
    */
    public function getModels() {
        return $this->models;
    }
    
    /**
    * Returns the model defined by the parameter $model.
    */
    public function getModel($model) {
        return $this->models[$model];
    }
    
    /**
    * Sets the model of the parameter $model.
    */
    public function setModel($name, $model) {
        $this->models[$name] = $model;
    }
    
    /**
    * Sets the user model.
    */
    public function setUserModel($userModel) {
        $this->models['userModel'] = $userModel;
    }
    
    /**
    * Returns the user model.
    */
    public function getUserModel() {
        return $this->models['userModel'];
    }
    
    /**
    * Sets the alert model.
    */
    public function setAlertModel($alertModel) {
        $this->models['alertModel'] = $alertModel;
    }
    
    /**
    * Returns the alert model.
    */
    public function getAlertModel() {
        return $this->models['alertModel'];
    }
    
    /**
    * Sets the userfield model.
    */
    public function setUserFieldModel($userFieldModel) {
        $this->models['userFieldModel'] = $userFieldModel;
    }
    
    /**
    * Returns the userfield model.
    */
    public function getUserFieldModel() {
        return $this->models['userFieldModel'];
    }
    
    /**
    * Sets the avatar model.
    */
    public function setAvatarModel($avatarModel) {
        $this->models['avatarModel'] = $avatarModel;
    }
    
    /**
    * Returns the avatar model.
    */
    public function getAvatarModel() {
        return $this->models['avatarModel'];
    }
    
    /**
    * Returns the database model.
    */
    public function getDatabase() {
        return $this->getModel('database');
    }
} 

class Post {
    public static function stripThreadValues(&$post) {
        unset($post['reply_count']);
        unset($post['view_count']);
        unset($post['sticky']);
        unset($post['discussion_state']);
        unset($post['discussion_open']);
        unset($post['discussion_type']);
        unset($post['first_post_id']);
        unset($post['first_post_likes']);
        unset($post['last_post_date']);
        unset($post['last_post_id']);
        unset($post['last_post_user_id']);
        unset($post['last_post_username']);
        unset($post['prefix_id']);
        unset($post['thread_user_id']);
        unset($post['thread_username']);
        unset($post['thread_post_date']);
    }
    public static function preparePostConditions($db, $model, array $conditions) {
        $sqlConditions = array();

        if (!empty($conditions['forum_id']) && empty($conditions['node_id'])) {
            $conditions['node_id'] = $conditions['forum_id'];
        }

        if (!empty($conditions['node_id'])) {
            if (is_array($conditions['node_id'])) {
                $sqlConditions[] = 'thread.node_id IN (' . $db->quote($conditions['node_id']) . ')';
            } else {
                $sqlConditions[] = 'thread.node_id = ' . $db->quote($conditions['node_id']);
            }
        }

        if (!empty($conditions['thread_id'])) {
            if (is_array($conditions['thread_id'])) {
                $sqlConditions[] = 'post.thread_id IN (' . $db->quote($conditions['thread_id']) . ')';
            } else {
                $sqlConditions[] = 'post.thread_id = ' . $db->quote($conditions['thread_id']);
            }
        }

        if (!empty($conditions['prefix_id'])) {
            if (is_array($conditions['prefix_id'])) {
                $sqlConditions[] = 'thread.prefix_id IN (' . $db->quote($conditions['prefix_id']) . ')';
            } else {
                $sqlConditions[] = 'thread.prefix_id = ' . $db->quote($conditions['prefix_id']);
            }
        }

        if (!empty($conditions['post_date']) && is_array($conditions['post_date'])) {
            list($operator, $cutOff) = $conditions['post_date'];

            $model->assertValidCutOffOperator($operator);
            $sqlConditions[] = "post.post_date $operator " . $db->quote($cutOff);
        }

        // thread starter
        if (isset($conditions['user_id'])) {
            $sqlConditions[] = 'post.user_id = ' . $db->quote($conditions['user_id']);
        }

        return $model->getConditionsForClause($sqlConditions);
    }
}

/**
* This class contains all the functions and all the relevant data of a XenForo resource.
*/
class Resource {
    private $data;
    
    /**
    * Default constructor.
    */
    public function __construct($data) {
        $this->data = $data;
    }

    /**
    * Returns an array with that conists of limited data.
    */
    public static function getLimitedData($resource) {
       return array('id'               => $resource->getID(),
                    'title'            => $resource->getTitle(),
                    'author_id'        => $resource->getAuthorUserID(),
                    'author_username'  => $resource->getAuthorUsername(),
                    'state'            => $resource->getState(),
                    'creation_date'    => $resource->getCreationDate(),
                    'category_id'      => $resource->getCategoryID(),
                    'version_id'       => $resource->getCurrentVersionID(),
                    'description_id'   => $resource->getDescriptionUpdateID(),
                    'thread_id'        => $resource->getDiscussionThreadID(),
                    'external_url'     => $resource->getExternalURL(),
                    'price'            => $resource->getPrice(),
                    'currency'         => $resource->getCurrency(),
                    'times_downloaded' => $resource->getTimesDownloaded(),
                    'times_rated'      => $resource->getTimesRated(),
                    'rating_sum'       => $resource->getRatingSum(),
                    'rating_avg'       => $resource->getAverageRating(),
                    'rating_weighted'  => $resource->getWeightedRating(),
                    'times_updated'    => $resource->getTimesUpdated(),
                    'times_reviewed'   => $resource->getTimesReviewed(),
                    'last_update'      => $resource->getLastUpdateDate());
    }

    /**
    * Returns an array which contains all the data of the resource.
    */
    public function getData() {
        return $this->data;
    }

    /**
    * Returns TRUE if the resource is valid, returns FALSE if not.
    */
    public function isValid() {
        return $this->data != NULL && is_array($this->data) && isset($this->data['resource_id']) && $this->data['resource_id'] != NULL;
    }

    /**
    * Returns the ID of the resource.
    */
    public function getID() {
        return $this->data['resource_id'];
    }

    /**
    * Returns the title of the resource.
    */
    public function getTitle() {
        return $this->data['title'];
    }

    /**
    * Returns the tag line of the resource.
    */
    public function getTagLine() {
        return $this->data['tag_line'];
    }

    /**
    * Returns the ID of the author.
    */
    public function getAuthorUserID() {
        return $this->data['user_id'];
    }

    /**
    * Returns the username of the author.
    */
    public function getAuthorUsername() {
        return $this->data['username'];
    }


    /**
    * Returns the state of the resource.
    * TODO
    */
    public function getState() {
        return $this->data['resource_state'];
    }

    /**
    * Returns the creation date of the resource.
    */
    public function getCreationDate() {
        return $this->data['resource_date'];
    }

    /**
    * Returns the category ID of the resource.
    */
    public function getCategoryID() {
        return $this->data['resource_category_id'];
    }

    /**
    * Returns the current version ID of the resource.
    */
    public function getCurrentVersionID() {
        return $this->data['current_version_id'];
    }

    /**
    * Returns the current description update ID of the resource.
    */
    public function getDescriptionUpdateID() {
        return $this->data['description_update_id'];
    }

    /**
    * Returns the discussion thread ID of the resource.
    */
    public function getDiscussionThreadID() {
        return $this->data['discussion_thread_id'];
    }

    /**
    * Returns the external URL of the resource.
    */
    public function getExternalURL() {
        return $this->data['external_url'];
    }

    /**
    * Returns TRUE if the resource is fileless, FALSE if not.
    */
    public function isFileless() {
        return $this->data['is_fileless'] == 1;
    }

    /**
    * Returns the external purchase URL of the resource if it has any.
    */
    public function getExternalPurchaseURL() {
        return $this->data['external_purchase_url'];
    }

    /**
    * Returns the price of the resource.
    */
    public function getPrice() {
        return $this->data['price'];
    }

    /**
    * Returns the currency of the price of the resource.
    */
    public function getCurrency() {
        return $this->data['currency'];
    }

    /**
    * Returns the amount of times the resource has been downloaded.
    */
    public function getTimesDownloaded() {
        return $this->data['download_count'];
    }

    /**
    * Returns the amount of times the resource has been rated.
    */
    public function getTimesRated() {
        return $this->data['rating_count'];
    }

    /**
    * Returns the sum of the ratings.
    */
    public function getRatingSum() {
        return $this->data['rating_sum'];
    }

    /**
    * Returns the average rating of the resource.
    */
    public function getAverageRating() {
        return $this->data['rating_avg'];
    }

    /**
    * Returns the weighted rating of the resource.
    */
    public function getWeightedRating() {
        return $this->data['rating_weighted'];
    }

    /**
    * Returns the amount of times the resource has been updated.
    */
    public function getTimesUpdated() {
        return $this->data['update_count'];
    }

    /**
    * Returns the amount of times the resource has been reviewed.
    */
    public function getTimesReviewed() {
        return $this->data['review_count'];
    }

    /**
    * Returns the last update date of the resource.
    */
    public function getLastUpdateDate() {
        return $this->data['last_update'];
    }

    /**
    * Returns the alternative support URL of the resource.
    */
    public function getAlternativeSupportURL() {
        return $this->data['alt_support_url'];
    }

    /**
    * Returns TRUE if the resource had first visible.
    */
    public function hadFirstVisible() {
        return $this->data['had_first_visible'] == 1;
    }
}   

/**
* This class contains all the functions and all the relevant data of a XenForo addon.
*/
class Addon {
    private $data;
    
    /**
    * Default constructor.
    */
    public function __construct($data) {
        $this->data = $data;
    }

    /**
    * Returns an array with that conists of limited data.
    */
    public static function getLimitedData($addon) {
       return array('id'      => $addon->getID(),
                    'title'   => $addon->getTitle(),
                    'version' => $addon->getVersionString(),
                    'enabled' => $addon->isEnabled(),
                    'url'     => $addon->getURL());
    }

    /**
    * Returns an array which contains all the data of the addon.
    */
    public function getData() {
        return $this->data;
    }

    /**
    * Returns TRUE if the addon is installed, returns FALSE if not.
    */
    public function isInstalled() {
        return $this->data != NULL && is_array($this->data) && isset($this->data['addon_id']) && $this->data['addon_id'] != NULL;
    }

    /**
    * Returns TRUE if the addon is enabled, returns FALSE if not.
    */
    public function isEnabled() {
        return $this->data['active'] == 1;
    }

    /**
    * Returns the ID of the addon.
    */
    public function getID() {
        return $this->data['addon_id'];
    }

    /**
    * Returns the title of the addon.
    */
    public function getTitle() {
        return $this->data['title'];
    }

    /**
    * Returns the version string of the addon.
    */
    public function getVersionString() {
        return $this->data['version_string'];
    }

    /**
    * Returns the version ID of the addon.
    */
    public function getVersionID() {
        return $this->data['version_id'];
    }

    /**
    * Returns the URL of the addon.
    */
    public function getURL() {
        return $this->data['url'];
    }

    /**
    * Returns the install callback class of the addon.
    */
    public function getInstallCallbackClass() {
        return $this->data['install_callback_class'];
    }

    /**
    * Returns the install callback method of the addon.
    */
    public function getInstallCallbackMethod() {
        return $this->data['install_callback_method'];
    }

    /**
    * Returns the uninstall callback class of the addon.
    */
    public function getUninstallCallbackClass() {
        return $this->data['uninstall_callback_class'];
    }

    /**
    * Returns the uninstall callback method of the addon.
    */
    public function getUninstallCallbackMethod() {
        return $this->data['uninstall_callback_class'];
    }
}

/**
* This class contains all the functions and all the relevant data of a XenForo user.
*/
class User {
    public $data;
    private $models, $registered = FALSE;
    
    /**
    * Default constructor.
    */
    public function __construct($models, $data) {
        $this->models = $models;
        $this->data = $data;
        if (!empty($data)) {
            $this->registered = TRUE;
        }
    }
    
    /**
    * Returns an array which contains all the data of the user.
    */
    public function getData() {
        return $this->data;
    }
    
    /**
    * Returns all the alerts and relevant information regarding the alerts.
    */
    public function getAlerts($type = 'fetchRecent') {
        /* 
        * Options are:
        *   - fetchPopupItems: Fetch alerts viewed in the last options:alertsPopupExpiryHours hours.
        *   - fetchRecent:     Fetch alerts viewed in the last options:alertExpiryDays days.
        *   - fetchAll:        Fetch alerts regardless of their view_date.
        *
        * For more information, see /library/XenForo/Model/Alert.php.
        */
        $types = array('fetchPopupItems', 'fetchRecent', 'fetchAll');
        if (!in_array($type, $types)) {
            $type = 'fetchRecent';
        }
        return $this->models->getAlertModel()->getAlertsForUser($this->getID(), $type);
    }
    
    /**
    * Returns the ID of the user.
    */
    public function getID() {
        return $this->data['user_id'];
    }
    
    /**
    * Returns the username of the user.
    */
    public function getUsername() {
        return $this->data['username'];
    }
    
    /**
    * Returns the email of the user.
    */
    public function getEmail() {
        return $this->data['email'];
    }
    
    /**
    * Returns the avatar URL of the user.
    */
    public function getAvatar($size) {
        if ($this->data['gravatar']) {
            return XenForo_Template_Helper_Core::getAvatarUrl($this->data, $size);
        } else if (!empty($this->data['avatar_date'])) {
            return 'http://' . $_SERVER['HTTP_HOST'] . '/' . XenForo_Template_Helper_Core::getAvatarUrl($this->data, $size, 'custom');
        } else {
            return 'http://' . $_SERVER['HTTP_HOST'] . '/' . XenForo_Template_Helper_Core::getAvatarUrl($this->data, $size, 'default');
        }
    }
    
    /**
    * Returns if the user is registered or not.
    */
    public function isRegistered() {
        return $this->registered;
    }
    
    /**
    * Returns TRUE if the user is a global moderator.
    */
    public function isModerator() {
        return $this->data['is_moderator'] == 1;
    }
    
    /**
    * Returns TRUE if the user an administrator.
    */
    public function isAdmin() {
        return $this->data['is_admin'] == 1;
    }
    
    /**
    * Returns TRUE if the user is banned.
    */
    public function isBanned() {
        return $this->data['is_banned'] == 1;
    }
    
    /**
    * Returns the authentication record of the user.
    */
    public function getAuthenticationRecord() {
        return $this->models->getUserModel()->getUserAuthenticationRecordByUserId($this->data['user_id']); 
    }
    
    /**
    * Verifies the password of the user. 
    */
    public function validateAuthentication($password) {
        if (strlen($password) == 64) {
            $record = $this->getAuthenticationRecord();
            $ddata = unserialize($record['data']);
            return $ddata['hash'] == $password;
        } else {
            return $this->models->getUserModel()->validateAuthentication($this->data['username'], $password); 
        }
    }
    
    /**
    * Returns the amount of unread alerts.
    */
    public function getUnreadAlertsCount() {
        return $this->models->getUserModel()->getUnreadAlertsCount($this->getID()); 
    }

    /**
    * Returns the permission cache, if any.
    */
    public function getPermissionCache() {
        return $this->data['global_permission_cache'];
    }

}

/**
* This class contains all the relevant information about the visitor that performed the request.
*/
class Visitor {
    /*
    * Returns the IP of the visitor.
    */
    public static function getIP() {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else if(isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return NULL;
    }

    /*
    * Returns the User Agent of the visitor.
    */
    public static function getUserAgent() {
        if (isset($_SERVER['HTTP_USER_AGENT'])) { 
            return $_SERVER['HTTP_USER_AGENT']; 
        }
        return NULL;
    }

    /*
    * Returns the referer of the visitor.
    */
    public static function getReferer() {
        if (isset($_SERVER['HTTP_REFERER'])) { 
            return $_SERVER['HTTP_REFERER']; 
        }
        return NULL;
    }
}
?>
