<?php 
    class Sitemap extends Modules {
        public function __init() {
            if (!isset($_SERVER['DOCUMENT_ROOT']))
                cancel_module("sitemap", __("Sitemap module cannot determine the server's document root.", "sitemap"));

            $actions = array("add_post",
                             "add_page",
                             "update_post",
                             "update_page",
                             "delete_post",
                             "delete_page");

            foreach ($actions as $action)
                $this->addAlias($action, "make_sitemap", 8);
        }

        static function __install() {
            Config::current()->set("module_sitemap",
                                   array("blog_changefreq" => "daily",
                                         "pages_changefreq" => "yearly",
                                         "posts_changefreq" => "monthly"));
        }

        static function __uninstall() {
            Config::current()->remove("module_sitemap");
        }

        public function settings_nav($navs) {
            if (Visitor::current()->group->can("change_settings"))
                $navs["sitemap_settings"] = array("title" => __("Sitemap", "sitemap"));

            return $navs;
        }

        public function admin_sitemap_settings($admin) {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $admin->display("pages".DIR."sitemap_settings",
                                       array("changefreq" => array("hourly"  => __("Hourly", "sitemap"),
                                                                   "daily"   => __("Daily", "sitemap"),
                                                                   "weekly"  => __("Weekly", "sitemap"),
                                                                   "monthly" => __("Monthly", "sitemap"),
                                                                   "yearly"  => __("Yearly", "sitemap"),
                                                                   "never"   => __("Never", "sitemap"))));

            if (!isset($_POST['hash']) or $_POST['hash'] != authenticate())
                show_403(__("Access Denied"), __("Invalid authentication token."));

            fallback($_POST['blog_changefreq'], "daily");
            fallback($_POST['pages_changefreq'], "yearly");
            fallback($_POST['posts_changefreq'], "monthly");

            Config::current()->set("module_sitemap",
                                   array("blog_changefreq" => $_POST['blog_changefreq'],
                                         "pages_changefreq" => $_POST['pages_changefreq'],
                                         "posts_changefreq" => $_POST['posts_changefreq']));

            Flash::notice(__("Settings updated."), "sitemap_settings");
        }

        /**
         * Function: make_sitemap
         * Generates a sitemap of the blog and writes it to the document root.
         */
        public function make_sitemap() {
            if ($this->cancelled)
                return;

            $results = SQL::current()->select("posts",
                                              "id",
                                              array("status" => "public"),
                                              array("id DESC"))->fetchAll();

            $ids = array();

            foreach ($results as $result)
                $ids[] = $result["id"];

            if (!empty($ids))
                $posts = Post::find(array("where" => array("id" => $ids)));
            else
                $posts = array();

            $pages = Page::find(array("where" => array("show_in_list" => true),
                                      "order" => "list_order ASC"));

            $config = Config::current();
            $settings = $config->module_sitemap;

            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            $xml.= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            $xml.= '<url>'."\n".
                   '<loc>'.$config->url.'/</loc>'."\n".
                   '<lastmod>'.when("c", time()).'</lastmod>'."\n".
                   '<changefreq>'.$settings["blog_changefreq"].'</changefreq>'."\n".
                   '</url>'."\n";

            foreach ($posts as $post) {
                $lastmod = ($post->updated) ? $post->updated_at : $post->created_at ;

                $xml.= '<url>'."\n".
                       '<loc>'.$post->url().'</loc>'."\n".
                       '<lastmod>'.when("c", $lastmod).'</lastmod>'."\n".
                       '<changefreq>'.$settings["posts_changefreq"].'</changefreq>'."\n".
                       '<priority>'.(($post->pinned) ? "1.0" : "0.5").'</priority>'."\n".
                       '</url>'."\n";
            }

            foreach ($pages as $page) {
                $lastmod = ($page->updated) ? $page->updated_at : $page->created_at ;

                $xml.= '<url>'."\n".
                       '<loc>'.$page->url().'</loc>'."\n".
                       '<lastmod>'.when("c", $lastmod).'</lastmod>'."\n".
                       '<changefreq>'.$settings["pages_changefreq"].'</changefreq>'."\n".
                       '</url>'."\n";
            }

            $xml.= '</urlset>'."\n";

            @file_put_contents($_SERVER['DOCUMENT_ROOT'].DIR."sitemap.xml", $xml);
        }
    }
