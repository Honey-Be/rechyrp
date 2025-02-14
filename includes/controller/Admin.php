<?php
    /**
     * Class: AdminController
     * The logic controlling the administration console.
     */
    class AdminController implements Controller {
        # Array: $urls
        # An array of clean URL => dirty URL translations.
        public $urls = array(
            '|/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/$|' => '/?action=$1&amp;$2=$3&amp;$4=$5',
            '|/([^/]+)/([^/]+)/([^/]+)/$|'                 => '/?action=$1&amp;$2=$3'
        );

        # Boolean: $displayed
        # Has anything been displayed?
        public $displayed = false;

        # Array: $context
        # Contains the context for various admin pages, to be passed to the Twig templates.
        public $context = array();

        # Boolean: $clean
        # Does this controller support clean URLs?
        public $clean = false;

        # Boolean: $feed
        # Is the current page a feed?
        public $feed = false;

        # Integer: $post_limit
        # Item limit for pagination.
        public $post_limit = 10;

        # String: $base
        # The base path for this controller.
        public $base = "admin";

        # Array: $protected
        # Methods that cannot respond to actions.
        public $protected = array("__construct", "parse", "navigation_context", "display", "current");

        /**
         * Function: __construct
         * Loads the Twig parser and sets up the l10n domain.
         */
        private function __construct() {
            $chain = array(new Twig_Loader_Filesystem(MAIN_DIR.DIR."admin"));

            $config = Config::current();

            foreach ($config->enabled_modules as $module)
                if (is_dir(MODULES_DIR.DIR.$module.DIR."admin"))
                    $chain[] = new Twig_Loader_Filesystem(MODULES_DIR.DIR.$module.DIR."admin");

            foreach ($config->enabled_feathers as $feather)
                if (is_dir(FEATHERS_DIR.DIR.$feather.DIR."admin"))
                    $chain[] = new Twig_Loader_Filesystem(FEATHERS_DIR.DIR.$feather.DIR."admin");

            $loader = new Twig_Loader_Chain($chain);

            $this->twig = new Twig_Environment($loader,
                                               array("debug" => DEBUG,
                                                     "strict_variables" => DEBUG,
                                                     "charset" => "UTF-8",
                                                     "cache" => (CACHE_TWIG ? CACHES_DIR.DIR."twig" : false),
                                                     "autoescape" => false));

            $this->twig->addExtension(new Leaf());
            $this->twig->registerUndefinedFunctionCallback("twig_callback_missing_function");
            $this->twig->registerUndefinedFilterCallback("twig_callback_missing_filter");

            # Load the theme translator.
            load_translator("admin", MAIN_DIR.DIR."admin".DIR."locale");

            # Set the limit for pagination.
            $this->post_limit = $config->admin_per_page;
        }

        /**
         * Function: parse
         * Route constructor calls this to determine the action based on user privileges.
         */
        public function parse($route) {
            $visitor = Visitor::current();
            $config = Config::current();

            if (empty($route->action) or $route->action == "write") {
                # "Write > Post", if they can add posts or drafts and at least one feather is enabled.
                if (!empty($config->enabled_feathers) and $visitor->group->can("add_post", "add_draft"))
                    return $route->action = "write_post";

                # "Write > Page", if they can add pages.
                if ($visitor->group->can("add_page"))
                    return $route->action = "write_page";
            }

            if (empty($route->action) or $route->action == "manage") {
                # "Manage > Posts", if they can manage any posts.
                if (Post::any_editable() or Post::any_deletable())
                    return $route->action = "manage_posts";

                # "Manage > Pages", if they can manage pages.
                if ($visitor->group->can("edit_page", "delete_page"))
                    return $route->action = "manage_pages";

                # "Manage > Users", if they can manage users.
                if ($visitor->group->can("add_user", "edit_user", "delete_user"))
                    return $route->action = "manage_users";

                # "Manage > Groups", if they can manage groups.
                if ($visitor->group->can("add_group", "edit_group", "delete_group"))
                    return $route->action = "manage_groups";

                # "Manage > Import", if they can import content.
                if ($visitor->group->can("add_post", "add_page", "add_group", "add_user"))
                    return $route->action = "import";

                # "Manage > Export", if they can export content.
                if ($visitor->group->can("export_content"))
                    return $route->action = "export";
            }

            if (empty($route->action) or $route->action == "settings") {
                # "General Settings", if they can configure the installation.
                if ($visitor->group->can("change_settings"))
                    return $route->action = "general_settings";
            }

            if (empty($route->action) or $route->action == "extend") {
                # "Modules", if they can can enable/disable extensions.
                if ($visitor->group->can("toggle_extensions"))
                    return $route->action = "modules";
            }

            Trigger::current()->filter($route->action, "admin_determine_action");

            # Show a 403 if we can't determine an allowed action for the visitor;
            # otherwise the action would fallback to "index" and fail with a 404.
            if (!isset($route->action))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to view this area."));
        }

        /**
         * Function: write_post
         * Post writing.
         */
        public function write_post() {
            if (!Visitor::current()->group->can("add_post", "add_draft"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add posts."));

            $config = Config::current();

            if (empty($config->enabled_feathers))
                Flash::notice(__("You must enable at least one feather in order to write a post."), "feathers");

            fallback($_SESSION['latest_feather'], reset($config->enabled_feathers));
            fallback($_GET['feather'], $_SESSION['latest_feather']);

            if (!feather_enabled($_GET['feather']))
                show_404(__("Not Found"), __("Feather not found."));

            $_SESSION['latest_feather'] = $_GET['feather'];

            Trigger::current()->filter($options, array("write_post_options", "post_options"));

            $this->display("pages".DIR."write_post",
                           array("groups" => Group::find(array("order" => "id ASC")),
                                 "options" => $options,
                                 "feathers" => Feathers::$instances,
                                 "feather" => Feathers::$instances[$_GET['feather']]));
        }

        /**
         * Function: add_post
         * Adds a post when the form is submitted.
         */
        public function add_post() {
            $visitor = Visitor::current();

            if (!$visitor->group->can("add_post", "add_draft"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add posts."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (!feather_enabled($_POST['feather']))
                show_404(__("Not Found"), __("Feather not found."));

            if (!isset($_POST['draft']) and !$visitor->group->can("add_post"))
                $_POST['draft'] = "true";

            $post = Feathers::$instances[$_POST['feather']]->submit();

            Flash::notice(__("Post created!"), (($post->status == "public") ? $post->url() : "write_post"));
        }

        /**
         * Function: edit_post
         * Post editing.
         */
        public function edit_post() {
            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to edit a post."), null, 400);

            $post = new Post($_GET['id'], array("drafts" => true, "filter" => false));

            if ($post->no_results)
                Flash::warning(__("Post not found."), "manage_posts");

            if (!$post->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this post."));

            Trigger::current()->filter($options, array("edit_post_options", "post_options"), $post);

            $this->display("pages".DIR."edit_post",
                           array("post" => $post,
                                 "groups" => Group::find(array("order" => "id ASC")),
                                 "options" => $options,
                                 "feather" => Feathers::$instances[$post->feather]));
        }

        /**
         * Function: update_post
         * Updates a post when the form is submitted.
         */
        public function update_post() {
            $visitor = Visitor::current();

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to update a post."), null, 400);

            if (!isset($_POST['draft']) and !$visitor->group->can("add_post"))
                $_POST['draft'] = "true";

            if (isset($_POST['publish']) and $visitor->group->can("add_post"))
                $_POST['status'] = "public";

            $post = new Post($_POST['id'], array("drafts" => true));

            if ($post->no_results)
                show_404(__("Not Found"), __("Post not found."));

            if (!$post->editable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this post."));

            $post = Feathers::$instances[$post->feather]->update($post);

            Flash::notice(__("Post updated.").' <a href="'.$post->url().'">'.__("View post &rarr;").'</a>', "manage_posts");
        }

        /**
         * Function: delete_post
         * Post deletion (confirm page).
         */
        public function delete_post() {
            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to delete a post."), null, 400);

            $post = new Post($_GET['id'], array("drafts" => true));

            if ($post->no_results)
                Flash::warning(__("Post not found."), "manage_posts");

            if (!$post->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this post."));

            $this->display("pages".DIR."delete_post", array("post" => $post));
        }

        /**
         * Function: destroy_post
         * Destroys a post (the real deal).
         */
        public function destroy_post() {
            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to delete a post."), null, 400);

            if (!isset($_POST['destroy']) or $_POST['destroy'] != "indubitably")
                redirect("manage_posts");

            $post = new Post($_POST['id'], array("drafts" => true));

            if ($post->no_results)
                show_404(__("Not Found"), __("Post not found."));

            if (!$post->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this post."));

            Post::delete($post->id);

            Flash::notice(__("Post deleted."), "manage_posts");
        }

        /**
         * Function: manage_posts
         * Post managing.
         */
        public function manage_posts() {
            if (!Post::any_editable() and !Post::any_deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage any posts."));

            fallback($_GET['query'], "");
            list($where, $params) = keywords($_GET['query'], "post_attributes.value LIKE :query OR url LIKE :query", "posts");

            if (!empty($_GET['month']))
                $where["created_at LIKE"] = when("Y-m-%", $_GET['month']);

            $visitor = Visitor::current();

            if (!$visitor->group->can("view_draft", "edit_draft", "edit_post", "delete_draft", "delete_post"))
                $where["user_id"] = $visitor->id;

            $results = Post::find(array("placeholders" => true,
                                        "drafts" => true,
                                        "where" => $where,
                                        "params" => $params));

            $ids = array();

            foreach ($results[0] as $result)
                $ids[] = $result["id"];

            if (!empty($ids))
                $posts = new Paginator(Post::find(array("placeholders" => true,
                                                        "drafts" => true,
                                                        "where" => array("id" => $ids))),
                                       $this->post_limit);
            else
                $posts = new Paginator(array());

            foreach ($posts->paginated as &$post) {
                if ($ids = $post->groups()) {
                    $group_names = array();
                    $group_classes = array();

                    foreach ($ids as $id) {
                        $group = new Group($id);

                        if (!$group->no_results) {
                            $group_names[] = $group->name;
                            $group_classes[] = "group-".$group->id;
                        }
                    }

                    $post->status_name = join(", ", $group_names);
                    $post->status_class = join(" ", $group_classes);
                } else {
                    $post->status_name = camelize($post->status, true);
                    $post->status_class = $post->status;
                }
            }

            $this->display("pages".DIR."manage_posts", array("posts" => $posts));
        }

        /**
         * Function: write_page
         * Page creation.
         */
        public function write_page() {
            if (!Visitor::current()->group->can("add_page"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add pages."));

            $this->display("pages".DIR."write_page", array("pages" => Page::find()));
        }

        /**
         * Function: add_page
         * Adds a page when the form is submitted.
         */
        public function add_page() {
            if (!Visitor::current()->group->can("add_page"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add pages."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['title']))
                error(__("Error"), __("Title cannot be blank."), null, 422);

            if (empty($_POST['body']))
                error(__("Error"), __("Body cannot be blank."), null, 422);

            fallback($_POST['parent_id'], 0);
            fallback($_POST['status'], "public");
            fallback($_POST['list_priority'], 0);
            fallback($_POST['slug'], $_POST['title']);

            $public = in_array($_POST['status'], array("listed", "public"));
            $listed = in_array($_POST['status'], array("listed", "teased"));
            $list_order = empty($_POST['list_order']) ? (int) $_POST['list_priority'] : (int) $_POST['list_order'] ;

            $page = Page::add($_POST['title'],
                              $_POST['body'],
                              null,
                              $_POST['parent_id'],
                              $public,
                              $listed,
                              $list_order);

            Flash::notice(__("Page created!"), $page->url());
        }

        /**
         * Function: edit_page
         * Page editing.
         */
        public function edit_page() {
            if (!Visitor::current()->group->can("edit_page"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit this page."));

            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to edit a page."), null, 400);

            $page = new Page($_GET['id'], array("filter" => false));

            if ($page->no_results)
                Flash::warning(__("Page not found."), "manage_pages");

            $this->display("pages".DIR."edit_page",
                           array("page" => $page,
                                 "pages" => Page::find(array("where" => array("id not" => $page->id)))));
        }

        /**
         * Function: update_page
         * Updates a page when the form is submitted.
         */
        public function update_page() {
            if (!Visitor::current()->group->can("edit_page"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit pages."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to edit a page."), null, 400);

            if (empty($_POST['title']))
                error(__("Error"), __("Title cannot be blank."), null, 422);

            if (empty($_POST['body']))
                error(__("Error"), __("Body cannot be blank."), null, 422);

            $page = new Page($_POST['id']);

            if ($page->no_results)
                show_404(__("Not Found"), __("Page not found."));

            fallback($_POST['parent_id'], 0);
            fallback($_POST['status'], "public");
            fallback($_POST['list_priority'], 0);

            $public = in_array($_POST['status'], array("listed", "public"));
            $listed = in_array($_POST['status'], array("listed", "teased"));
            $list_order = empty($_POST['list_order']) ? (int) $_POST['list_priority'] : (int) $_POST['list_order'] ;

            $page = $page->update($_POST['title'],
                                  $_POST['body'],
                                  null,
                                  $_POST['parent_id'],
                                  $public,
                                  $listed,
                                  $list_order);

            Flash::notice(__("Page updated.").' <a href="'.$page->url().'">'.__("View page &rarr;").'</a>', "manage_pages");
        }

        /**
         * Function: delete_page
         * Page deletion (confirm page).
         */
        public function delete_page() {
            if (!Visitor::current()->group->can("delete_page"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete pages."));

            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to delete a page."), null, 400);

            $page = new Page($_GET['id']);

            if ($page->no_results)
                Flash::warning(__("Page not found."), "manage_pages");

            $this->display("pages".DIR."delete_page", array("page" => $page));
        }

        /**
         * Function: destroy_page
         * Destroys a page.
         */
        public function destroy_page() {
            if (!Visitor::current()->group->can("delete_page"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete pages."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to delete a page."), null, 400);

            if (!isset($_POST['destroy']) or $_POST['destroy'] != "indubitably")
                redirect("manage_pages");

            $page = new Page($_POST['id']);

            if ($page->no_results)
                show_404(__("Not Found"), __("Page not found."));

            foreach ($page->children as $child)
                if (isset($_POST['destroy_children']))
                    Page::delete($child->id, true);
                else
                    $child->update($child->title,
                                   $child->body,
                                   null,
                                   0,
                                   $child->public,
                                   $child->show_in_list,
                                   $child->list_order,
                                   null,
                                   $child->url);

            Page::delete($page->id);

            Flash::notice(__("Page deleted."), "manage_pages");
        }

        /**
         * Function: manage_pages
         * Page managing.
         */
        public function manage_pages() {
            $visitor = Visitor::current();

            if (!$visitor->group->can("edit_page", "delete_page"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage pages."));

            fallback($_GET['query'], "");
            list($where, $params) = keywords($_GET['query'], "title LIKE :query OR body LIKE :query", "pages");

            $this->display("pages".DIR."manage_pages",
                           array("pages" => new Paginator(Page::find(array("placeholders" => true,
                                                                           "where" => $where,
                                                                           "params" => $params)),
                                                          $this->post_limit)));
        }

        /**
         * Function: new_user
         * User creation.
         */
        public function new_user() {
            if (!Visitor::current()->group->can("add_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add users."));

            $config = Config::current();

            $this->display("pages".DIR."new_user",
                           array("default_group" => new Group($config->default_group),
                                 "groups" => Group::find(array("where" => array("id not" => array($config->guest_group,
                                                                                                  $config->default_group)),
                                                               "order" => "id DESC"))));
        }

        /**
         * Function: add_user
         * Add a user when the form is submitted.
         */
        public function add_user() {
            if (!Visitor::current()->group->can("add_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add users."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['login']))
                error(__("Error"), __("Please enter a username for the account."), null, 422);

            $check = new User(array("login" => $_POST['login']));

            if (!$check->no_results)
                error(__("Error"), __("That username is already in use."), null, 409);

            if (empty($_POST['password1']) or empty($_POST['password2']))
                error(__("Error"), __("Passwords cannot be blank."), null, 422);

            if ($_POST['password1'] != $_POST['password2'])
                error(__("Error"), __("Passwords do not match."), null, 422);

            if (password_strength($_POST['password1']) < 100)
                Flash::message(__("Please consider setting a stronger password for this user."));

            if (empty($_POST['email']))
                error(__("Error"), __("Email address cannot be blank."), null, 422);

            if (!is_email($_POST['email']))
                error(__("Error"), __("Invalid email address."), null, 422);

            if (!empty($_POST['website']) and !is_url($_POST['website']))
                error(__("Error"), __("Invalid website URL."), null, 422);

            if (!empty($_POST['website']))
                $_POST['website'] = add_scheme($_POST['website']);

            $config = Config::current();

            fallback($_POST['full_name'], "");
            fallback($_POST['website'], "");
            fallback($_POST['group'], $config->default_group);

            $user = User::add($_POST['login'],
                              User::hashPassword($_POST['password1']),
                              $_POST['email'],
                              $_POST['full_name'],
                              $_POST['website'],
                              $_POST['group'],
                              ($config->email_activation) ? false : true);

            if (!$user->approved)
                correspond("activate", array("login" => $user->login,
                                             "to"    => $user->email,
                                             "link"  => fix($config->url, true).
                                                        "/?action=activate&amp;login=".urlencode($user->login).
                                                        "&amp;token=".token(array($user->login, $user->email))));

            Flash::notice(__("User added."), "manage_users");
        }

        /**
         * Function: edit_user
         * User editing.
         */
        public function edit_user() {
            if (!Visitor::current()->group->can("edit_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit users."));

            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to edit a user."), null, 400);

            $user = new User($_GET['id']);

            if ($user->no_results)
                Flash::warning(__("User not found."), "manage_users");

            $this->display("pages".DIR."edit_user",
                           array("user" => $user,
                                 "groups" => Group::find(array("order" => "id ASC",
                                                               "where" => array("id not" => Config::current()->guest_group)))));
        }

        /**
         * Function: update_user
         * Updates a user when the form is submitted.
         */
        public function update_user() {
            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to edit a user."), null, 400);

            $visitor = Visitor::current();
            $config = Config::current();

            if (!$visitor->group->can("edit_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit users."));

            $check_name = new User(null, array("where" => array("login" => $_POST['login'],
                                                                "id not" => $_POST['id'])));

            if (!$check_name->no_results)
                error(__("Error"), __("That username is already in use."), null, 409);

            $user = new User($_POST['id']);

            if ($user->no_results)
                show_404(__("Not Found"), __("User not found."));

            if (!empty($_POST['new_password1']))
                if (empty($_POST['new_password2']) or $_POST['new_password1'] != $_POST['new_password2'])
                    error(__("Error"), __("Passwords do not match."), null, 422);
                elseif (password_strength($_POST['new_password1']) < 100)
                    Flash::message(__("Please consider setting a stronger password for this user."));

            $password = (!empty($_POST['new_password1'])) ?
                User::hashPassword($_POST['new_password1']) : $user->password ;

            if (empty($_POST['email']))
                error(__("Error"), __("Email address cannot be blank."), null, 422);

            if (!is_email($_POST['email']))
                error(__("Error"), __("Invalid email address."), null, 422);

            if (!empty($_POST['website']) and !is_url($_POST['website']))
                error(__("Error"), __("Invalid website URL."), null, 422);

            if (!empty($_POST['website']))
                $_POST['website'] = add_scheme($_POST['website']);

            fallback($_POST['full_name'], "");
            fallback($_POST['website'], "");
            fallback($_POST['group'], $config->default_group);

            $user = $user->update($_POST['login'],
                                  $password,
                                  $_POST['email'],
                                  $_POST['full_name'],
                                  $_POST['website'],
                                  $_POST['group'],
                                  (!$user->approved and $config->email_activation) ? false : true);

            if (!$user->approved)
                correspond("activate", array("login" => $user->login,
                                             "to"    => $user->email,
                                             "link"  => fix($config->url, true).
                                                        "/?action=activate&amp;login=".urlencode($user->login).
                                                        "&amp;token=".token(array($user->login, $user->email))));

            Flash::notice(__("User updated."), "manage_users");
        }

        /**
         * Function: delete_user
         * User deletion.
         */
        public function delete_user() {
            if (!Visitor::current()->group->can("delete_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete users."));

            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to delete a user."), null, 400);

            $user = new User($_GET['id']);

            if ($user->no_results)
                Flash::warning(__("User not found."), "manage_users");

            if ($user->id == Visitor::current()->id)
                Flash::warning(__("You cannot delete your own account."), "manage_users");

            $this->display("pages".DIR."delete_user",
                           array("user" => $user,
                                 "users" => User::find(array("where" => array("id not" => $user->id)))));
        }

        /**
         * Function: destroy_user
         * Destroys a user.
         */
        public function destroy_user() {
            if (!Visitor::current()->group->can("delete_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete users."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to delete a user."), null, 400);

            if (!isset($_POST['destroy']) or $_POST['destroy'] != "indubitably")
                redirect("manage_users");

            $user = new User($_POST['id']);

            if ($user->no_results)
                show_404(__("Not Found"), __("User not found."));

            $sql = SQL::current();

            if (!empty($user->posts))
                if (!empty($_POST['move_posts'])) {
                    $posts_user = new User($_POST['move_posts']);

                    if ($posts_user->no_results)
                        error(__("Gone"), __("New owner for posts does not exist."), null, 410);

                    foreach ($user->posts as $post)
                        $sql->update("posts",
                                     array("id" => $post->id),
                                     array("user_id" => $posts_user->id));
                } else
                    foreach ($user->posts as $post)
                        Post::delete($post->id);

            if (!empty($user->pages))
                if (!empty($_POST['move_pages'])) {
                    $pages_user = new User($_POST['move_pages']);

                    if ($pages_user->no_results)
                        error(__("Gone"), __("New owner for pages does not exist."), null, 410);

                    foreach ($user->pages as $page)
                        $sql->update("pages",
                                     array("id" => $page->id),
                                     array("user_id" => $pages_user->id));
                } else
                    foreach ($user->pages as $page)
                        Page::delete($page->id);

            User::delete($user->id);

            Flash::notice(__("User deleted."), "manage_users");
        }

        /**
         * Function: manage_users
         * User managing.
         */
        public function manage_users() {
            $visitor = Visitor::current();

            if (!$visitor->group->can("add_user", "edit_user", "delete_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage users."));

            fallback($_GET['query'], "");
            list($where, $params) = keywords($_GET['query'],
                "login LIKE :query OR full_name LIKE :query OR email LIKE :query OR website LIKE :query", "users");

            $this->display("pages".DIR."manage_users",
                           array("users" => new Paginator(User::find(array("placeholders" => true,
                                                                           "where" => $where,
                                                                           "params" => $params)),
                                                          $this->post_limit)));
        }

        /**
         * Function: new_group
         * Group creation.
         */
        public function new_group() {
            if (!Visitor::current()->group->can("add_group"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add groups."));

            $this->display("pages".DIR."new_group",
                           array("permissions" => Group::list_permissions()));
        }

        /**
         * Function: add_group
         * Adds a group when the form is submitted.
         */
        public function add_group() {
            if (!Visitor::current()->group->can("add_group"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to add groups."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['name']))
                error(__("Error"), __("Please enter a name for the group."), null, 422);

            fallback($_POST['permissions'], array());

            $check = new Group(null, array("where" => array("name" => $_POST['name'])));

            if (!$check->no_results)
                error(__("Error"), __("That group name is already in use."), null, 409);

            Group::add($_POST['name'], array_keys($_POST['permissions']));

            Flash::notice(__("Group added."), "manage_groups");
        }

        /**
         * Function: edit_group
         * Group editing.
         */
        public function edit_group() {
            if (!Visitor::current()->group->can("edit_group"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit groups."));

            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to edit a group."), null, 400);

            $group = new Group($_GET['id']);

            if ($group->no_results)
                Flash::warning(__("Group not found."), "manage_groups");

            $this->display("pages".DIR."edit_group",
                           array("group" => $group,
                                 "permissions" => Group::list_permissions()));
        }

        /**
         * Function: update_group
         * Updates a group when the form is submitted.
         */
        public function update_group() {
            if (!Visitor::current()->group->can("edit_group"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to edit groups."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to edit a group."), null, 400);

            fallback($_POST['name'], "");
            fallback($_POST['permissions'], array());

            $check_name = new Group(null, array("where" => array("name" => $_POST['name'],
                                                                 "id not" => $_POST['id'])));

            if (!$check_name->no_results)
                error(__("Error"), __("That group name is already in use."), null, 409);

            $group = new Group($_POST['id']);

            if ($group->no_results)
                show_404(__("Not Found"), __("Group not found."));

            $group = $group->update($_POST['name'], array_keys($_POST['permissions']));

            Flash::notice(__("Group updated."), "manage_groups");
        }

        /**
         * Function: delete_group
         * Group deletion (confirm page).
         */
        public function delete_group() {
            if (!Visitor::current()->group->can("delete_group"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete groups."));

            if (empty($_GET['id']) or !is_numeric($_GET['id']))
                error(__("No ID Specified"), __("An ID is required to delete a group."), null, 400);

            $group = new Group($_GET['id']);

            if ($group->no_results)
                show_404(__("Not Found"), __("Group not found."));

            if ($group->id == Visitor::current()->group->id)
                Flash::warning(__("You cannot delete your own group."), "manage_groups");

            $this->display("pages".DIR."delete_group",
                           array("group" => $group,
                                 "groups" => Group::find(array("where" => array("id not" => $group->id),
                                                               "order" => "id ASC"))));
        }

        /**
         * Function: destroy_group
         * Destroys a group.
         */
        public function destroy_group() {
            if (!Visitor::current()->group->can("delete_group"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete groups."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['id']) or !is_numeric($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to delete a group."), null, 400);

            if (!isset($_POST['destroy']) or $_POST['destroy'] != "indubitably")
                redirect("manage_groups");

            $group = new Group($_POST['id']);

            if ($group->no_results)
                show_404(__("Not Found"), __("Group not found."));

            # Assign users to new member group.
            if (!empty($group->users))
                if (!empty($_POST['move_group'])) {
                    $member_group = new Group($_POST['move_group']);

                    if ($member_group->no_results)
                        error(__("Gone"), __("New member group does not exist."), null, 410);

                    foreach ($group->users as $user)
                        $user->update($user->login,
                                      $user->password,
                                      $user->email,
                                      $user->full_name,
                                      $user->website,
                                      $member_group->id);
                } else
                    error(__("Error"), __("New member group must be specified."), null, 422);

            $config = Config::current();

            # Set new default group.
            if ($config->default_group == $group->id)
                if (!empty($_POST['default_group'])) {
                    $default_group = new Group($_POST['default_group']);

                    if ($default_group->no_results)
                        error(__("Gone"), __("New default group does not exist."), null, 410);

                    $config->set("default_group", $default_group->id);
                } else
                    error(__("Error"), __("New default group must be specified."), null, 422);

            # Set new guest group.
            if ($config->guest_group == $group->id)
                if (!empty($_POST['guest_group'])) {
                    $guest_group = new Group($_POST['guest_group']);

                    if ($guest_group->no_results)
                        error(__("Gone"), __("New guest group does not exist."), null, 410);

                    $config->set("guest_group", $guest_group->id);
                } else
                    error(__("Error"), __("New guest group must be specified."), null, 422);

            $sql = SQL::current();

            # Set group-specific posts to private status.
            foreach ($sql->select("posts",
                                  "id",
                                  array("status LIKE" => "%{".$group->id."}%"))->fetchAll() as $post) {

                $sql->update("posts",
                             array("id" => $post["id"]),
                             array("status" => "private"));
            }

            Group::delete($group->id);

            Flash::notice(__("Group deleted."), "manage_groups");
        }

        /**
         * Function: manage_groups
         * Group managing.
         */
        public function manage_groups() {
            $visitor = Visitor::current();

            if (!$visitor->group->can("add_group", "edit_group", "delete_group"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage groups."));

            if (!empty($_GET['search'])) {
                $user = new User(array("login" => $_GET['search']));

                if (!$user->no_results)
                    $groups = new Paginator(array($user->group));
                else
                    $groups = new Paginator(array());
            } else
                $groups = new Paginator(Group::find(array("placeholders" => true, "order" => "id ASC")),
                                        $this->post_limit);

            $this->display("pages".DIR."manage_groups", array("groups" => $groups));
        }

        /**
         * Function: export
         * Export content from this installation.
         */
        public function export() {
            $config  = Config::current();
            $trigger = Trigger::current();
            $visitor = Visitor::current();
            $exports = array(); # Use this to store export data. It will be tested to determine if anything was selected.

            if (!$visitor->group->can("export_content"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to export content."));

            if (empty($_POST))
                return $this->display("pages".DIR."export");

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            $trigger->call("before_export");

            if (isset($_POST['posts'])) {
                fallback($_POST['filter_posts'], "");
                list($where, $params) = keywords($_POST['filter_posts'],
                    "post_attributes.value LIKE :query OR url LIKE :query", "posts");

                $visitor = Visitor::current();

                if (!$visitor->group->can("view_draft", "edit_draft", "edit_post", "delete_draft", "delete_post"))
                    $where["user_id"] = $visitor->id;

                $results = Post::find(array("placeholders" => true,
                                            "drafts" => true,
                                            "where" => $where,
                                            "params" => $params));

                $ids = array();

                foreach ($results[0] as $result)
                    $ids[] = $result["id"];

                if (!empty($ids))
                    $posts = Post::find(array("drafts" => true, "where" => array("id" => $ids), "order" => "id ASC"),
                                        array("filter" => false));
                else
                    $posts = array();

                $posts_atom = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
                $posts_atom.= '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:chyrp="http://chyrp.net/export/1.0/">'."\n";
                $posts_atom.= '<title>'.fix($config->name).' | Posts</title>'."\n";
                $posts_atom.= '<subtitle>'.fix($config->description).'</subtitle>'."\n";
                $posts_atom.= '<id>'.fix($config->url).'</id>'."\n";
                $posts_atom.= '<updated>'.date("c").'</updated>'."\n";
                $posts_atom.= '<link href="'.fix($config->url, true).'" rel="alternate" type="text/html" />'."\n";
                $posts_atom.= '<generator uri="http://chyrp.net/" version="'.CHYRP_VERSION.'">Chyrp</generator>'."\n";

                foreach ($posts as $post) {
                    $updated = ($post->updated) ? $post->updated_at : $post->created_at ;
                    $title = oneof($post->title(), ucfirst($post->feather));

                    $posts_atom.= '<entry xml:base="'.fix($post->url(), true).'">'."\n";
                    $posts_atom.= '<title type="html">'.fix($title, false, true).'</title>'."\n";
                    $posts_atom.= '<id>'.fix(url("id/post/".$post->id, MainController::current())).'</id>'."\n";
                    $posts_atom.= '<updated>'.when("c", $updated).'</updated>'."\n";
                    $posts_atom.= '<published>'.when("c", $post->created_at).'</published>'."\n";
                    $posts_atom.= '<author chyrp:user_id="'.$post->user_id.'">'."\n";
                    $posts_atom.= '<name>'.fix(oneof($post->user->full_name, $post->user->login)).'</name>'."\n";

                    if (!empty($post->user->website))
                        $posts_atom.= '<uri>'.fix($post->user->website).'</uri>'."\n";

                    $posts_atom.= '<chyrp:login>'.fix($post->user->login, false, true).'</chyrp:login>'."\n";
                    $posts_atom.= '</author>'."\n";
                    $posts_atom.= '<content type="application/xml">'."\n";

                    foreach ($post->attributes as $key => $val)
                        $posts_atom.= '<'.$key.'>'.fix($val, false, true).'</'.$key.'>'."\n";

                    $posts_atom.= '</content>'."\n";

                    foreach (array("feather", "clean", "url", "pinned", "status") as $attr)
                        $posts_atom.= '<chyrp:'.$attr.'>'.fix($post->$attr, false, true).'</chyrp:'.$attr.'>'."\n";

                    $trigger->filter($posts_atom, "posts_export", $post);

                    $posts_atom.= '</entry>'."\n";
                }

                $posts_atom.= '</feed>'."\n";
                $exports["posts.atom"] = $posts_atom;
            }

            if (isset($_POST['pages'])) {
                fallback($_POST['filter_pages'], "");
                list($where, $params) = keywords($_POST['filter_pages'], "title LIKE :query OR body LIKE :query", "pages");

                $pages = Page::find(array("where" => $where, "params" => $params, "order" => "id ASC"),
                                    array("filter" => false));

                $pages_atom = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
                $pages_atom.= '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:chyrp="http://chyrp.net/export/1.0/">'."\n";
                $pages_atom.= '<title>'.fix($config->name).' | Pages</title>'."\n";
                $pages_atom.= '<subtitle>'.fix($config->description).'</subtitle>'."\n";
                $pages_atom.= '<id>'.fix($config->url).'</id>'."\n";
                $pages_atom.= '<updated>'.date("c").'</updated>'."\n";
                $pages_atom.= '<link href="'.fix($config->url, true).'" rel="alternate" type="text/html" />'."\n";
                $pages_atom.= '<generator uri="http://chyrp.net/" version="'.CHYRP_VERSION.'">Chyrp</generator>'."\n";

                foreach ($pages as $page) {
                    $updated = ($page->updated) ? $page->updated_at : $page->created_at ;

                    $pages_atom.= '<entry xml:base="'.fix($page->url(), true).'" chyrp:parent_id="'.$page->parent_id.'">'."\n";
                    $pages_atom.= '<title type="html">'.fix($page->title, false, true).'</title>'."\n";
                    $pages_atom.= '<id>'.fix(url("id/page/".$page->id, MainController::current())).'</id>'."\n";
                    $pages_atom.= '<updated>'.when("c", $updated).'</updated>'."\n";
                    $pages_atom.= '<published>'.when("c", $page->created_at).'</published>'."\n";
                    $pages_atom.= '<author chyrp:user_id="'.fix($page->user_id).'">'."\n";
                    $pages_atom.= '<name>'.fix(oneof($page->user->full_name, $page->user->login)).'</name>'."\n";

                    if (!empty($page->user->website))
                        $pages_atom.= '<uri>'.fix($page->user->website).'</uri>'."\n";

                    $pages_atom.= '<chyrp:login>'.fix($page->user->login, false, true).'</chyrp:login>'."\n";
                    $pages_atom.= '</author>'."\n";
                    $pages_atom.= '<content type="html">'.fix($page->body, false, true).'</content>'."\n";

                    foreach (array("public", "show_in_list", "list_order", "clean", "url") as $attr)
                        $pages_atom.= '<chyrp:'.$attr.'>'.fix($page->$attr, false, true).'</chyrp:'.$attr.'>'."\n";


                    $trigger->filter($pages_atom, "pages_export", $page);

                    $pages_atom.= '</entry>'."\n";
                }

                $pages_atom.= '</feed>'."\n";
                $exports["pages.atom"] = $pages_atom;
            }

            if (isset($_POST['groups'])) {
                fallback($_POST['filter_groups'], "");
                list($where, $params) = keywords($_POST['filter_groups'], "name LIKE :query", "groups");

                $groups = Group::find(array("where" => $where, "params" => $params, "order" => "id ASC"));

                $groups_json = array();

                foreach ($groups as $index => $group)
                    $groups_json[$group->name] = $group->permissions;

                $exports["groups.json"] = json_set($groups_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            if (isset($_POST['users'])) {
                fallback($_POST['filter_users'], "");
                list($where, $params) = keywords($_POST['filter_users'],
                    "login LIKE :query OR full_name LIKE :query OR email LIKE :query OR website LIKE :query", "users");

                $users = User::find(array("where" => $where, "params" => $params, "order" => "id ASC"));

                $users_json = array();

                $exclude = array("no_results",
                                 "group_id",
                                 "group",
                                 "id",
                                 "login",
                                 "belongs_to",
                                 "has_many",
                                 "has_one",
                                 "queryString");

                foreach ($users as $user) {
                    $users_json[$user->login] = array();

                    foreach ($user as $name => $attr)
                        if (!in_array($name, $exclude))
                            $users_json[$user->login][$name] = $attr;
                        elseif ($name == "group_id")
                            $users_json[$user->login]["group"] = $user->group->name;
                }

                $exports["users.json"] = json_set($users_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $trigger->filter($exports, "export");

            if (empty($exports))
                Flash::warning(__("You did not select anything to export."), "export");

            $filename = sanitize(camelize($config->name), false, true)."_Export_".date("Y-m-d");
            $filepath = tempnam(sys_get_temp_dir(), "zip");

            if (class_exists("ZipArchive")) {
                $zip = new ZipArchive;
                $err = $zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                if ($err !== true)
                    error(__("Error"), _f("Failed to export files because of ZipArchive error %d.", $err));

                foreach ($exports as $name => $contents)
                    $zip->addFromString($name, $contents);

                $zip->close();
                $bitstream = file_get_contents($filepath);
                unlink($filepath);
                file_attachment($bitstream, $filename.".zip");
            } else
                file_attachment(reset($exports), key($exports)); # ZipArchive not installed: send the first export item.
        }

        /**
         * Function: import
         * Import content to this installation.
         */
        public function import() {
            $config  = Config::current();
            $trigger = Trigger::current();
            $visitor = Visitor::current();
            $sql = SQL::current();
            $imports = array(); # Use this to store import data. It will be tested to determine if anything was selected.

            if (!$visitor->group->can("add_post", "add_page", "add_group", "add_user"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to import content."));

            if (empty($_POST))
                return $this->display("pages".DIR."import");

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (isset($_FILES['posts_file']) and upload_tester($_FILES['posts_file']))
                if (!$imports["posts"] = simplexml_load_file($_FILES['posts_file']['tmp_name']) or
                     $imports["posts"]->generator != "Chyrp")
                    Flash::warning(__("Posts export file is invalid."), "import");

            if (isset($_FILES['pages_file']) and upload_tester($_FILES['pages_file']))
                if (!$imports["pages"] = simplexml_load_file($_FILES['pages_file']['tmp_name']) or
                     $imports["pages"]->generator != "Chyrp")
                    Flash::warning(__("Pages export file is invalid."), "import");

            if (isset($_FILES['groups_file']) and upload_tester($_FILES['groups_file']))
                if (!is_array($imports["groups"] = json_get(file_get_contents($_FILES['groups_file']['tmp_name']), true)))
                    Flash::warning(__("Groups export file is invalid."), "import");

            if (isset($_FILES['users_file']) and upload_tester($_FILES['users_file']))
                if (!is_array($imports["users"] = json_get(file_get_contents($_FILES['users_file']['tmp_name']), true)))
                    Flash::warning(__("Users export file is invalid."), "import");

            $trigger->filter($imports, "before_import");

            if (empty($imports))
                Flash::warning(__("You did not select anything to import."), "import");

            if (shorthand_bytes(ini_get("memory_limit")) < 20971520)
                ini_set("memory_limit", "20M");

            if (ini_get("max_execution_time") !== 0)
                set_time_limit(300);

            function media_url_scan(&$value) {
                $regexp_url = preg_quote($_POST['media_url'], "/");

                if (preg_match_all("/{$regexp_url}([^\.\!,\?;\"\'<>\(\)\[\]\{\}\s\t ]+)\.([a-zA-Z0-9]+)/", $value, $media))
                    foreach ($media[0] as $matched_url) {
                        $filename = upload_from_url($matched_url);
                        $value = str_replace($matched_url, uploaded($filename), $value);
                    }
            }

            if (isset($imports["groups"])) {
                if (!$visitor->group->can("add_group"))
                    show_403(__("Access Denied"), __("You do not have sufficient privileges to add groups."));

                foreach ($imports["groups"] as $name => $permissions) {
                    $group = new Group(array("name" => (string) $name));

                    if ($group->no_results) {
                        $group = Group::add($name, $permissions);
                        $trigger->call("import_chyrp_group", $group);
                    }
                }
            }

            if (isset($imports["users"])) {
                if (!$visitor->group->can("add_user"))
                    show_403(__("Access Denied"), __("You do not have sufficient privileges to add users."));

                foreach ($imports["users"] as $login => $attributes) {
                    $user = new User(array("login" => (string) $login));

                    if ($user->no_results) {
                        $group = new Group(array("name" => (string) fallback($attributes["group"])));

                        $user = User::add($login,
                                          fallback($attributes["password"], User::hashPassword(random(8))),
                                          fallback($attributes["email"], ""),
                                          fallback($attributes["full_name"], ""),
                                          fallback($attributes["website"], ""),
                                          (!$group->no_results) ? $group->id : $config->default_group,
                                          fallback($attributes["approved"], false),
                                          fallback($attributes["joined_at"]), datetime());

                        $trigger->call("import_chyrp_user", $user);
                    }
                }
            }

            if (isset($imports["posts"])) {
                if (!$visitor->group->can("add_post"))
                    show_403(__("Access Denied"), __("You do not have sufficient privileges to add posts."));

                foreach ($imports["posts"]->entry as $entry) {
                    $chyrp = $entry->children("http://chyrp.net/export/1.0/");
                    $login = $entry->author->children("http://chyrp.net/export/1.0/")->login;

                    $user = new User(array("login" => unfix((string) $login)));

                    $values = array();

                    foreach ($entry->content->children() as $value)
                        $values[$value->getName()] = unfix((string) $value);

                    if (!empty($_POST['media_url']))
                        array_walk_recursive($values, "media_url_scan");

                    $values["imported_from"] = "chyrp";

                    $updated = ((string) $entry->updated != (string) $entry->published);

                    $post = Post::add($values,
                                      unfix((string) $chyrp->clean),
                                      Post::check_url(unfix((string) $chyrp->url)),
                                      unfix((string) $chyrp->feather),
                                      (!$user->no_results) ? $user->id : $visitor->id,
                                      (int) (bool) unfix((string) $chyrp->pinned),
                                      unfix((string) $chyrp->status),
                                      datetime((string) $entry->published),
                                      ($updated) ? datetime((string) $entry->updated) : null,
                                      false);

                    $trigger->call("import_chyrp_post", $entry, $post);
                }
            }

            if (isset($imports["pages"])) {
                if (!$visitor->group->can("add_page"))
                    show_403(__("Access Denied"), __("You do not have sufficient privileges to add pages."));

                foreach ($imports["pages"]->entry as $entry) {
                    $chyrp = $entry->children("http://chyrp.net/export/1.0/");
                    $attr  = $entry->attributes("http://chyrp.net/export/1.0/");
                    $login = $entry->author->children("http://chyrp.net/export/1.0/")->login;

                    $user = new User(array("login" => unfix((string) $login)));

                    $updated = ((string) $entry->updated != (string) $entry->published);

                    $page = Page::add(unfix((string) $entry->title),
                                      unfix((string) $entry->content),
                                      (!$user->no_results) ? $user->id : $visitor->id,
                                      (int) unfix((string) $attr->parent_id),
                                      (int) (bool) unfix((string) $chyrp->public),
                                      (int) (bool) unfix((string) $chyrp->show_in_list),
                                      (int) unfix((string) $chyrp->list_order),
                                      unfix((string) $chyrp->clean),
                                      Page::check_url(unfix((string) $chyrp->url)),
                                      datetime((string) $entry->published),
                                      ($updated) ? datetime((string) $entry->updated) : null);

                    $trigger->call("import_chyrp_page", $entry, $page);
                }
            }

            $trigger->call("import", $imports);

            Flash::notice(__("Chyrp Lite content successfully imported!"), "import");
        }

        /**
         * Function: modules
         * Module enabling/disabling.
         */
        public function modules() {
            if (!Visitor::current()->group->can("toggle_extensions"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to toggle extensions."));

            $config = Config::current();

            $this->context["enabled_modules"]  = array();
            $this->context["disabled_modules"] = array();

            if (!$open = @opendir(MODULES_DIR))
                error(__("Error"), __("Could not read modules directory."));

            $classes = array();

            while (($folder = readdir($open)) !== false) {
                if (strpos($folder, ".") === 0 or !is_file(MODULES_DIR.DIR.$folder.DIR.$folder.".php"))
                    continue;

                load_translator($folder, MODULES_DIR.DIR.$folder.DIR."locale");

                if (!isset($classes[$folder]))
                    $classes[$folder] = array($folder);
                else
                    array_unshift($classes[$folder], $folder);

                $info = load_info(MODULES_DIR.DIR.$folder.DIR."info.php");

                # List of modules conflicting with this one (installed or not).

                if (!empty($info["conflicts"])) {
                    $classes[$folder][] = "conflicts";

                    foreach ($info["conflicts"] as $conflict)
                        if (file_exists(MODULES_DIR.DIR.$conflict.DIR.$conflict.".php")) {
                            $classes[$folder][] = "conflict_".$conflict;

                            if (module_enabled($conflict))
                                if (!in_array("error", $classes[$folder]))
                                    $classes[$folder][] = "error";
                        }
                }

                # List of modules depended on by this one (installed or not).

                if (!empty($info["dependencies"])) {
                    $classes[$folder][] = "dependencies";

                    foreach ($info["dependencies"] as $dependency) {
                        if (!file_exists(MODULES_DIR.DIR.$dependency.DIR.$dependency.".php")) {
                            if (!in_array("missing_dependency", $classes[$folder]))
                                $classes[$folder][] = "missing_dependency";

                            if (!in_array("error", $classes[$folder]))
                                $classes[$folder][] = "error";
                        } else {
                            if (!module_enabled($dependency))
                                if (!in_array("error", $classes[$folder]))
                                    $classes[$folder][] = "error";

                            fallback($classes[$dependency], array());
                            $classes[$dependency][] = "needed_by_".$folder;
                        }

                        $classes[$folder][] = "needs_".$dependency;
                    }
                }

                # We don't use the module_enabled() helper function to allow for disabling cancelled modules.
                $category = (in_array($folder, $config->enabled_modules)) ?
                    "enabled_modules" : "disabled_modules" ;

                $this->context[$category][$folder] = array_merge($info, array("classes" => $classes[$folder]));
            }

            closedir($open);
            $this->display("pages".DIR."modules");
        }

        /**
         * Function: feathers
         * Feather enabling/disabling.
         */
        public function feathers() {
            if (!Visitor::current()->group->can("toggle_extensions"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to toggle extensions."));

            $config = Config::current();

            $this->context["enabled_feathers"]  = array();
            $this->context["disabled_feathers"] = array();

            if (!$open = @opendir(FEATHERS_DIR))
                error(__("Error"), __("Could not read feathers directory."));

            while (($folder = readdir($open)) !== false) {
                if (strpos($folder, ".") === 0 or !is_file(FEATHERS_DIR.DIR.$folder.DIR.$folder.".php"))
                    continue;

                load_translator($folder, FEATHERS_DIR.DIR.$folder.DIR."locale");

                # We don't use the feather_enabled() helper function to allow for disabling cancelled feathers.
                $category = (in_array($folder, $config->enabled_feathers)) ?
                    "enabled_feathers" : "disabled_feathers" ;

                $this->context[$category][$folder] = load_info(FEATHERS_DIR.DIR.$folder.DIR."info.php");
            }

            closedir($open);
            $this->display("pages".DIR."feathers");
        }

        /**
         * Function: themes
         * Theme switching/previewing.
         */
        public function themes() {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (!empty($_SESSION['theme']))
                Flash::message(__("You are currently previewing a theme.").
                                 ' <a href="'.url("preview_theme").'">'.__("Stop &rarr;").'</a>');

            $this->context["themes"] = array();

            if (!$open = @opendir(THEMES_DIR))
                error(__("Error"), __("Could not read themes directory."));

            while (($folder = readdir($open)) !== false) {
                if (strpos($folder, ".") === 0 or !is_dir(THEMES_DIR.DIR.$folder))
                    continue;

                load_translator($folder, THEMES_DIR.DIR.$folder.DIR."locale");

                $this->context["themes"][$folder] = load_info(THEMES_DIR.DIR.$folder.DIR."info.php");
            }

            closedir($open);
            $this->display("pages".DIR."themes");
        }

        /**
         * Function: enable
         * Enables a module or feather.
         */
        public function enable() {
            $config  = Config::current();
            $visitor = Visitor::current();

            if (!$visitor->group->can("toggle_extensions"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to toggle extensions."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['extension']) or empty($_POST['type']))
                error(__("No Extension Specified"), __("You did not specify an extension to enable."), null, 400);

            $type          = ($_POST['type'] == "module") ? "module" : "feather" ;
            $name          = str_replace(array(".", DIR), "", $_POST['extension']);
            $enabled_array = ($type == "module") ? "enabled_modules" : "enabled_feathers" ;
            $folder        = ($type == "module") ? MODULES_DIR : FEATHERS_DIR ;
            $class_name    = camelize($name);

            if (in_array($name, $config->$enabled_array))
                error(__("Error"), __("Extension already enabled."), null, 409);

            if (!file_exists($folder.DIR.$name.DIR.$name.".php"))
                show_404(__("Not Found"), __("Extension not found."));

            load_translator($name, $folder.DIR.$name.DIR."locale");

            require $folder.DIR.$name.DIR.$name.".php";

            if (method_exists($class_name, "__install"))
                call_user_func(array($class_name, "__install"));

            $config->set($enabled_array, array_merge($config->$enabled_array, array($name)));

            foreach (load_info($folder.DIR.$name.DIR."info.php")["notifications"] as $message)
                Flash::message($message);

            Flash::notice(__("Extension enabled."), pluralize($type));
        }

        /**
         * Function: disable
         * Disables a module or feather.
         */
        public function disable() {
            $config  = Config::current();
            $visitor = Visitor::current();

            if (!$visitor->group->can("toggle_extensions"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to toggle extensions."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['extension']) or empty($_POST['type']))
                error(__("No Extension Specified"), __("You did not specify an extension to disable."), null, 400);

            $type          = ($_POST['type'] == "module") ? "module" : "feather" ;
            $name          = str_replace(array(".", DIR), "", $_POST['extension']);
            $enabled_array = ($type == "module") ? "enabled_modules" : "enabled_feathers" ;
            $folder        = ($type == "module") ? MODULES_DIR : FEATHERS_DIR ;
            $class_name    = camelize($name);

            if (!in_array($name, $config->$enabled_array))
                error(__("Error"), __("Extension already disabled."), null, 409);

            if (!file_exists($folder.DIR.$name.DIR.$name.".php"))
                show_404(__("Not Found"), __("Extension not found."));

            if (method_exists($class_name, "__uninstall"))
                call_user_func(array($class_name, "__uninstall"), !empty($_POST['confirm']));

            $config->set($enabled_array, array_diff($config->$enabled_array, array($name)));

            if ($type == "feather" and isset($_SESSION['latest_feather']) and $_SESSION['latest_feather'] == $name)
                unset($_SESSION['latest_feather']);

            Flash::notice(__("Extension disabled."), pluralize($type));
        }

        /**
         * Function: change_theme
         * Changes the theme.
         */
        public function change_theme() {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['theme']))
                error(__("No Theme Specified"), __("You did not specify which theme to select."), null, 400);

            if (!isset($_POST['change']) or $_POST['change'] != "indubitably")
                self::preview_theme();

            $theme = str_replace(array(".", DIR), "", $_POST['theme']);
            Config::current()->set("theme", $theme);

            load_translator($theme, THEMES_DIR.DIR.$theme.DIR."locale");

            foreach (load_info(THEMES_DIR.DIR.$theme.DIR."info.php")["notifications"] as $message)
                Flash::message($message);

            Flash::notice(__("Theme changed."), "themes");
        }

        /**
         * Function: preview_theme
         * Previews the theme.
         */
        public function preview_theme() {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            $trigger = Trigger::current();

            if (empty($_POST['theme'])) {
                unset($_SESSION['theme']);
                $trigger->call("preview_theme_stopped");
                Flash::notice(__("Preview stopped."), "themes");
            }

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            $_SESSION['theme'] = str_replace(array(".", DIR), "", $_POST['theme']);
            $trigger->call("preview_theme_started");
            Flash::notice(__("Preview started."), Config::current()->url);
        }

        /**
         * Function: general_settings
         * General Settings page.
         */
        public function general_settings() {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            # Ensure the default locale is always present in the list.
            $locales = array(array("code" => "en_US",
                                   "name" => lang_code("en_US")));

            if ($open = opendir(INCLUDES_DIR.DIR."locale")) {
                 while (($folder = readdir($open)) !== false)
                    if ($folder != "en_US" and preg_match("/^[a-z]{2}(_|-)[a-z]{2}$/i", $folder))
                        $locales[] = array("code" => $folder,
                                           "name" => lang_code($folder));

                closedir($open);
            }

            if (empty($_POST))
                return $this->display("pages".DIR."general_settings",
                                      array("locales" => $locales,
                                            "timezones" => timezones()));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (empty($_POST['email']))
                error(__("Error"), __("Email address cannot be blank."), null, 422);

            if (!is_email($_POST['email']))
                error(__("Error"), __("Invalid email address."), null, 422);

            if (empty($_POST['chyrp_url']))
                error(__("Error"), __("Chyrp URL cannot be blank."), null, 422);

            if (!is_url($_POST['chyrp_url']))
                error(__("Error"), __("Invalid Chyrp URL."), null, 422);

            if (!empty($_POST['url']) and !is_url($_POST['url']))
                error(__("Error"), __("Invalid canonical URL."), null, 422);

            $config = Config::current();

            fallback($_POST['name'], "");
            fallback($_POST['description'], "");
            fallback($_POST['url'], "");
            fallback($_POST['timezone'], "Atlantic/Reykjavik");
            fallback($_POST['locale'], "en_US");

            $check_updates_last = (empty($_POST['check_updates'])) ? 0 : $config->check_updates_last ;

            $config->set("name", strip_tags($_POST['name']));
            $config->set("description", strip_tags($_POST['description']));
            $config->set("chyrp_url", rtrim(add_scheme($_POST['chyrp_url']), "/"));
            $config->set("url", rtrim(add_scheme(oneof($_POST['url'], $_POST['chyrp_url'])), "/"));
            $config->set("email", $_POST['email']);
            $config->set("timezone", $_POST['timezone']);
            $config->set("locale", $_POST['locale']);
            $config->set("cookies_notification", !empty($_POST['cookies_notification']));
            $config->set("check_updates", !empty($_POST['check_updates']));
            $config->set("check_updates_last", $check_updates_last);

            Flash::notice(__("Settings updated."), "general_settings");
        }

        /**
         * Function: content_settings
         * Content Settings page.
         */
        public function content_settings() {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            $feed_formats = array(array("name" => "Atom",
                                        "class" => "AtomFeed"),
                                  array("name" => "RSS",
                                        "class" => "RSSFeed"),
                                  array("name" => "JSON",
                                        "class" => "JSONFeed"));

            if (empty($_POST))
                return $this->display("pages".DIR."content_settings", array("feed_formats" => $feed_formats));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            if (!empty($_POST['feed_url']) and !is_url($_POST['feed_url']))
                error(__("Error"), __("Invalid feed URL."), null, 422);

            fallback($_POST['posts_per_page'], 5);
            fallback($_POST['admin_per_page'], 25);
            fallback($_POST['feed_items'], 20);
            fallback($_POST['feed_url'], "");
            fallback($_POST['feed_format'], "AtomFeed");
            fallback($_POST['uploads_path'], "");
            fallback($_POST['uploads_limit'], 10);

            $separator = preg_quote(DIR, "~");
            preg_match("~^(".$separator.")?(.*?)(".$separator.")?$~", $_POST['uploads_path'], $matches);

            fallback($matches[1], DIR);
            fallback($matches[2], "uploads");
            fallback($matches[3], DIR);

            $config = Config::current();
            $config->set("posts_per_page", (int) $_POST['posts_per_page']);
            $config->set("admin_per_page", (int) $_POST['admin_per_page']);
            $config->set("feed_items", (int) $_POST['feed_items']);
            $config->set("feed_url", $_POST['feed_url']);
            $config->set("feed_format", $_POST['feed_format']);
            $config->set("uploads_path", $matches[1].$matches[2].$matches[3]);
            $config->set("uploads_limit", (int) $_POST['uploads_limit']);
            $config->set("send_pingbacks", !empty($_POST['send_pingbacks']));
            $config->set("enable_xmlrpc", !empty($_POST['enable_xmlrpc']));
            $config->set("enable_ajax", !empty($_POST['enable_ajax']));
            $config->set("enable_emoji", !empty($_POST['enable_emoji']));
            $config->set("enable_markdown", !empty($_POST['enable_markdown']));

            Flash::notice(__("Settings updated."), "content_settings");
        }

        /**
         * Function: user_settings
         * User Settings page.
         */
        public function user_settings() {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $this->display("pages".DIR."user_settings",
                                      array("groups" => Group::find(array("order" => "id DESC"))));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            fallback($_POST['default_group'], 0);
            fallback($_POST['guest_group'], 0);

            $correspond = (!empty($_POST['email_activation']) or !empty($_POST['email_correspondence'])) ? true : false ;

            $config = Config::current();
            $config->set("can_register", !empty($_POST['can_register']));
            $config->set("email_activation", !empty($_POST['email_activation']));
            $config->set("email_correspondence", $correspond);
            $config->set("default_group", (int) $_POST['default_group']);
            $config->set("guest_group", (int) $_POST['guest_group']);

            Flash::notice(__("Settings updated."), "user_settings");
        }

        /**
         * Function: route_settings
         * Route Settings page.
         */
        public function route_settings() {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $this->display("pages".DIR."route_settings");

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            $route = Route::current();
            $config = Config::current();

            if (!empty($_POST['clean_urls']) and htaccess_conf() === false) {
                Flash::warning(__("Clean URLs are disabled because the <em>.htaccess</em> file cannot be configured."));
                unset($_POST['clean_urls']);
            }

            if (!empty($_POST['enable_homepage']) and !$config->enable_homepage) {
                $route->add("/", "page;url=home");

                if (Page::check_url("home") == "home" ) {
                    $page = Page::add(__("My Awesome Homepage"),
                                      __("Nothing here yet!"),
                                      null,
                                      0,
                                      true,
                                      true,
                                      0,
                                      "home");
                    Flash::notice(__("Page created.").' <a href="'.$page->url().'">'.__("View page &rarr;").'</a>');
                }
            }

            if (empty($_POST['enable_homepage']) and $config->enable_homepage)
                $route->remove("/");

            fallback($_POST['post_url'], "(year)/(month)/(day)/(url)/");

            $config->set("clean_urls", !empty($_POST['clean_urls']));
            $config->set("post_url", trim($_POST['post_url'], "/ ")."/");
            $config->set("enable_homepage", !empty($_POST['enable_homepage']));

            Flash::notice(__("Settings updated."), "route_settings");
        }

        /**
         * Function: login
         * Mask for MainController->login().
         */
        public function login() {
            if (logged_in())
                Flash::notice(__("You are already logged in."), "/");

            $_SESSION['redirect_to'] = url("/");
            redirect(url("login", MainController::current()));
        }

        /**
         * Function: logout
         * Mask for MainController->logout().
         */
        public function logout() {
            redirect(url("logout", MainController::current()));
        }

        /**
         * Function: help
         * Serves help pages for core and extensions.
         */
        public function help() {
            if (empty($_GET['id']))
                error(__("Error"), __("Missing argument."), null, 400);

            $template = str_replace(DIR, "", $_GET['id']);

            return $this->display("help".DIR.$template, array(), __("Help"));
        }

        /**
         * Function: navigation_context
         * Returns the navigation context for Twig.
         */
        private function navigation_context($action) {
            $trigger = Trigger::current();
            $visitor = Visitor::current();

            $navigation = array();

            $navigation["write"]    = array("children" => array(), "selected" => false, "title" => __("Write"));
            $navigation["manage"]   = array("children" => array(), "selected" => false, "title" => __("Manage"));
            $navigation["settings"] = array("children" => array(), "selected" => false, "title" => __("Settings"));
            $navigation["extend"]   = array("children" => array(), "selected" => false, "title" => __("Extend"));

            $write    =& $navigation["write"]["children"];
            $manage   =& $navigation["manage"]["children"];
            $settings =& $navigation["settings"]["children"];
            $extend   =& $navigation["extend"]["children"];

            # Write:

            if ($visitor->group->can("add_page"))
                $write["write_page"] = array("title" => __("Page"));

            if ($visitor->group->can("add_draft", "add_post"))
                foreach (Config::current()->enabled_feathers as $feather) {
                    if (!feather_enabled($feather))
                        continue;

                    $name = load_info(FEATHERS_DIR.DIR.$feather.DIR."info.php")["name"];

                    $write["write_post/feather/".$feather] = array("title" => $name,
                                                                   "feather" => $feather);
                }

            $trigger->filter($write, "write_nav");

            foreach ($write as $child => &$attributes) {
                $attributes["selected"] = ($action == $child or
                    (isset($attributes["selected"]) and in_array($action, (array) $attributes["selected"])) or
                    (isset($_GET['feather']) and isset($attributes["feather"]) and $_GET['feather'] == $attributes["feather"]));

                if ($attributes["selected"] == true)
                    $navigation["write"]["selected"] = true;
            }

            # Manage:

            if (Post::any_editable() or Post::any_deletable())
                $manage["manage_posts"] = array("title" => __("Posts"),
                                                "selected" => array("edit_post", "delete_post"));

            if ($visitor->group->can("edit_page", "delete_page"))
                $manage["manage_pages"] = array("title" => __("Pages"),
                                                "selected" => array("edit_page", "delete_page"));

            if ($visitor->group->can("add_user", "edit_user", "delete_user"))
                $manage["manage_users"] = array("title" => __("Users"),
                                                "selected" => array("edit_user", "delete_user", "new_user"));

            if ($visitor->group->can("add_group", "edit_group", "delete_group"))
                $manage["manage_groups"] = array("title" => __("Groups"),
                                                 "selected" => array("edit_group", "delete_group", "new_group"));

            $trigger->filter($manage, "manage_nav");

            if ($visitor->group->can("add_post", "add_page", "add_group", "add_user"))
                $manage["import"] = array("title" => __("Import"));

            if ($visitor->group->can("export_content"))
                $manage["export"] = array("title" => __("Export"));

            foreach ($manage as $child => &$attributes) {
                $attributes["selected"] = ($action == $child or
                    (isset($attributes["selected"]) and in_array($action, (array) $attributes["selected"])));

                if ($attributes["selected"] == true)
                    $navigation["manage"]["selected"] = true;
            }

            # Settings:

            if ($visitor->group->can("change_settings")) {
                $settings["general_settings"] = array("title" => __("General"));
                $settings["content_settings"] = array("title" => __("Content"));
                $settings["user_settings"] = array("title" => __("Users"));
                $settings["route_settings"] = array("title" => __("Routes"));
            }

            $trigger->filter($settings, "settings_nav");

            foreach ($settings as $child => &$attributes) {
                $attributes["selected"] = ($action == $child or
                    (isset($attributes["selected"]) and in_array($action, (array) $attributes["selected"])));

                if ($attributes["selected"] == true)
                    $navigation["settings"]["selected"] = true;
            }

            # Extend:

            if ($visitor->group->can("toggle_extensions")) {
                $extend["modules"] = array("title" => __("Modules"));
                $extend["feathers"] = array("title" => __("Feathers"));
                $extend["themes"] = array("title" => __("Themes"));
            }

            $trigger->filter($extend, "extend_nav");

            foreach ($extend as $child => &$attributes) {
                $attributes["selected"] = ($action == $child or
                    (isset($attributes["selected"]) and in_array($action, (array) $attributes["selected"])));

                if ($attributes["selected"] == true)
                    $navigation["extend"]["selected"] = true;
            }

            return $navigation;
        }

        /**
         * Function: display
         * Displays the page.
         *
         * Parameters:
         *     $template - The template file to display (sans ".twig") relative to /admin/ for core and extensions.
         *     $context - The context to be supplied to Twig.
         *     $title - The title for the page. Defaults to a camelization of the action, e.g. foo_bar -> Foo Bar.
         */
        public function display($template, $context = array(), $title = "") {
            $config = Config::current();
            $route = Route::current();
            $trigger = Trigger::current();

            $this->displayed = true;

            $this->context                       = array_merge($context, $this->context);
            $this->context["ip"]                 = $_SERVER['REMOTE_ADDR'];
            $this->context["DIR"]                = DIR;
            $this->context["version"]            = CHYRP_VERSION;
            $this->context["codename"]           = CHYRP_CODENAME;
            $this->context["debug"]              = DEBUG;
            $this->context["now"]                = time();
            $this->context["site"]               = $config;
            $this->context["flash"]              = Flash::current();
            $this->context["theme"]              = Theme::current();
            $this->context["trigger"]            = $trigger;
            $this->context["route"]              = $route;
            $this->context["visitor"]            = Visitor::current();
            $this->context["visitor"]->logged_in = logged_in();
            $this->context["title"]              = fallback($title, camelize($template, true));
            $this->context["navigation"]         = $this->navigation_context($route->action);
            $this->context["feathers"]           = Feathers::$instances;
            $this->context["modules"]            = Modules::$instances;
            $this->context["POST"]               = $_POST;
            $this->context["GET"]                = $_GET;
            $this->context["sql_queries"]        =& SQL::current()->queries;
            $this->context["sql_debug"]          =& SQL::current()->debug;

            $trigger->filter($this->context, array("admin_context", "admin_context_".str_replace(DIR, "_", $template)));

            Update::check();

            $this->twig->display($template.".twig", $this->context);
        }

        /**
         * Function: current
         * Returns a singleton reference to the current class.
         */
        public static function & current() {
            static $instance = null;
            $instance = (empty($instance)) ? new self() : $instance ;
            return $instance;
        }
    }
