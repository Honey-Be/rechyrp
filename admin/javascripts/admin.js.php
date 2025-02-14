<?php
    define('JAVASCRIPT', true);
    require_once dirname(dirname(dirname(__FILE__))).DIRECTORY_SEPARATOR."includes".DIRECTORY_SEPARATOR."common.php";
?>
$(function() {
    toggle_all();
    validate_slug();
    validate_email();
    validate_url();
    validate_passwords();
    confirm_submit();
    auto_submit();
    Help.init();
    Write.init();
    Settings.init();
    Extend.init();
});
// Adds a master toggle to forms that have multiple checkboxes.
function toggle_all() {
    $("form[data-toggler]").each(function() {
        var all_on = true;
        var target = $(this);
        var parent = $("#" + $(this).attr("data-toggler"));
        var slaves = target.find(":checkbox");
        var master = Date.now().toString(16);

        slaves.each(function() {
            return all_on = $(this).prop("checked");
        });

        slaves.click(function(e) {
            slaves.each(function() {
                return all_on = $(this).prop("checked");
            });

            $("#" + master).prop("checked", all_on);
        });

        parent.append(
            [$("<label>").attr("for", master).text('<?php echo __("Toggle All", "admin"); ?>'),
            $("<input>", {
                "type": "checkbox",
                "name": "toggle",
                "id": master,
                "class": "checkbox"
            }).prop("checked", all_on).click(function(e) {
                slaves.prop("checked", $(this).prop("checked"));
            })]
        );
    });
}
// Validates slug fields.
function validate_slug() {
    $("input[name='slug']").keyup(function(e) {
        if (/^([a-z0-9\-]*)$/.test($(this).val()))
            $(this).removeClass("error");
        else
            $(this).addClass("error");
    });
}
// Validates email fields.
function validate_email() {
    $("input[type='email']").keyup(function(e) {
        if ($(this).val() != "" && !isEmail($(this).val()))
            $(this).addClass("error");
        else
            $(this).removeClass("error");
    });
}
// Validates URL fields.
function validate_url() {
    $("input[type='url']").keyup(function(e) {
        if ($(this).val() != "" && !isURL($(this).val()))
            $(this).addClass("error");
        else
            $(this).removeClass("error");
    });
}
// Tests the strength of #password1 and compares #password1 to #password2.
function validate_passwords() {
    passwords = $("input[type='password']").filter(function(index) {
        var id = $(this).attr("id");
        return (!!id) ? id.match(/password[1-2]$/) : false ;
    });

    passwords.first().keyup(function(e) {
        if (passwordStrength($(this).val()) > 99)
            $(this).addClass("strong");
        else
            $(this).removeClass("strong");
    });

    passwords.keyup(function(e) {
        if (passwords.first().val() != "" && passwords.first().val() != passwords.last().val())
            passwords.last().addClass("error");
        else
            passwords.last().removeClass("error");
    });

    passwords.parents("form").on("submit", function(e) {
        if (passwords.first().val() != passwords.last().val()) {
            e.preventDefault();
            alert('<?php echo __("Passwords do not match."); ?>');
        }
    });
}
// Asks the user to confirm form submission.
function confirm_submit() {
    $("form[data-confirm]").submit(function(e) {
        var text = $(this).attr("data-confirm") || '<?php echo __("Are you sure you want to proceed?", "admin"); ?>' ;

        if (!confirm(text.replace(/<[^>]+>/g, "")))
            e.preventDefault();
    });
}
// Submit a form when an element changes.
function auto_submit() {
    $("select[data-submit], input[data-submit][type='checkbox']").on("change", function(e) {
        $(this).parents("form").submit();
    });
}
var Route = {
    action: '<?php if (isset($_GET['action'])) echo addslashes($_GET['action']); ?>'
}
var Visitor = {
    token: '<?php if (same_origin()) echo authenticate(); ?>'
}
var Site = {
    url: '<?php echo addslashes($config->url); ?>',
    chyrp_url: '<?php echo addslashes($config->chyrp_url); ?>',
    ajax: <?php echo($config->enable_ajax ? "true" : "false"); ?> 
}
var Help = {
    init: function() {
        $(".help").on("click", function(e) {
            e.preventDefault();
            Help.show($(this).attr("href"));
        });
    },
    show: function(href) {
        $("<div>", {
            "role": "region",
        }).addClass("iframe_background").append(
            [$("<iframe>", {
                "src": href,
                "aria-label": '<?php echo __("Help", "admin"); ?>'
            }).addClass("iframe_foreground").loader().on("load", function() {
                $(this).loader(true);
            }),
            $("<img>", {
                "src": Site.chyrp_url + '/admin/images/icons/close.svg',
                "alt": '<?php echo __("Close", "admin"); ?>',
                "role": 'button',
                "aria-label": '<?php echo __("Close", "admin"); ?>'
            }).addClass("iframe_close_gadget").click(function() {
                $(this).parent().remove();
            })]
        ).click(function(e) {
            if (e.target === e.currentTarget)
                $(this).remove();
        }).insertAfter("#content");
    }
}
var Write = {
    init: function() {
        // Insert buttons for ajax previews.
        if (<?php echo($theme->file_exists("content".DIR."preview") ? "true" : "false"); ?>)
            $("#write_form *[data-preview], #edit_form *[data-preview]").each(function() {
                var target = $(this);

                $("label[for='" + target.attr("id") + "']").append(
                    $("<img>", {
                        "src": Site.chyrp_url + '/admin/images/icons/magnifier.svg',
                        "alt": '(<?php echo __("Preview this field", "admin"); ?>)',
                        "title": '<?php echo __("Preview this field", "admin"); ?>',
                    }).addClass("emblem preview").click(function(e) {
                        var content  = target.val();
                        var field    = target.attr("name");
                        var safename = $("input#feather").val() || "page";
                        var action   = (safename == "page") ? "preview_page" : "preview_post" ;

                        if (content != "") {
                            e.preventDefault();
                            Write.show(action, safename, field, content);
                        }
                    })
                );
            });
    },
    show: function(action, safename, field, content) {
        var uid = Date.now().toString(16);

        // Build a form targeting a named iframe.
        $("<form>", {
            "id": uid,
            "action": Site.chyrp_url + "/includes/ajax.php",
            "method": "post",
            "accept-charset": "UTF-8",
            "target": uid,
            "style": "display: none;"
        }).append(
            [$("<input>", {
                "type": "hidden",
                "name": "action",
                "value": action
            }),
            $("<input>", {
                "type": "hidden",
                "name": "safename",
                "value": safename
            }),
            $("<input>", {
                "type": "hidden",
                "name": "field",
                "value": field
            }),
            $("<input>", {
                "type": "hidden",
                "name": "content",
                "value": content
            }),
            $("<input>", {
                "type": "hidden",
                "name": "hash",
                "value": Visitor.token
            })]
        ).insertAfter("#content");

        // Build and display the named iframe.
        $("<div>", {
            "role": "region",
        }).addClass("iframe_background").append(
            [$("<iframe>", {
                "name": uid,
                "aria-label": '<?php echo __("Preview", "admin"); ?>'
            }).addClass("iframe_foreground").loader().on("load", function() {
                if (!!this.contentWindow.location && this.contentWindow.location != "about:blank")
                    $(this).loader(true);
            }),
            $("<img>", {
                "src": Site.chyrp_url + '/admin/images/icons/close.svg',
                "alt": '<?php echo __("Close", "admin"); ?>',
                "role": 'button',
                "aria-label": '<?php echo __("Close", "admin"); ?>'
            }).addClass("iframe_close_gadget").click(function() {
                $(this).parent().remove();
            })]
        ).click(function(e) {
            if (e.target === e.currentTarget)
                $(this).remove();
        }).insertAfter("#content");

        // Submit the form and destroy it immediately.
        $("#" + uid).submit().remove();
    }
}
var Settings = {
    init: function() {
        $("#email_correspondence").click(function() {
            if ($(this).prop("checked") == false)
                $("#email_activation").prop("checked", false);
        });

        $("#email_activation").click(function() {
            if ($(this).prop("checked") == true)
                $("#email_correspondence").prop("checked", true);
        });

        $("form#route_settings code.syntax").on("click", function(e) {
            var name = $(e.target).text();
            var post_url = $("form#route_settings input[name='post_url']");
            var regexp = new RegExp("(^|\\/)" + escapeRegExp(name) + "([\\/]|$)", "g");

            if (regexp.test(post_url.val())) {
                post_url.val(post_url.val().replace(regexp, function(match, before, after) {
                    if (before == "/" && after == "/")
                        return "/";
                    else
                        return "";
                }));
                $(e.target).removeClass("yay");
            } else {
                if (post_url.val() == "")
                    post_url.val(name);
                else
                    post_url.val(post_url.val().replace(/(\/?)?$/, "\/" + name));

                $(e.target).addClass("yay");
            }
        }).css("cursor", "pointer");

        $("form#route_settings input[name='post_url']").on("keyup", function(e) {
            $("form#route_settings code.syntax").each(function(){
                regexp = new RegExp("(/?|^)" + $(this).text() + "(/?|$)", "g");

                if ($(e.target).val().match(regexp))
                    $(this).addClass("yay");
                else
                    $(this).removeClass("yay");
            });
        }).trigger("keyup");
    }
}
var Extend = {
    init: function() {
        // Hide the confirmation checkbox and use a modal instead.
        $(".module_disabler_confirm, .feather_disabler_confirm").hide();
        $(".module_disabler, .feather_disabler").on("submit.confirm", Extend.confirm);
    },
    confirm: function(e) {
        e.preventDefault();

        var id = $(e.target).parents("li.module, li.feather").attr("id");
        var name = (!!id) ? id.replace(/^(module|feather)_/, "") : "" ;
        var text = $('label[for="confirm_' + name + '"]').html();

        // Display the modal if the text was found, and set the checkbox to the response.
        if (!!text)
            $('#confirm_' + name).prop("checked", confirm(text.replace(/<[^>]+>/g, "")));

        // Disable this handler and resubmit the form with the checkbox set accordingly.
        $(e.target).off("submit.confirm").submit();
    }
}
<?php $trigger->call("admin_javascript"); ?>
