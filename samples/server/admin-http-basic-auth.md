# Admin-only HTTP Basic authentication

Use this optional web-server layer when `/admin` needs an additional password prompt but public pages, secret links, feeds, Search, and routed media must remain reachable.

HTTP Basic authentication does not replace the Tinymash login. An administrator passes both layers. Store the password file outside the web document root, make it readable by the web server, serve the site only over HTTPS, and use `htpasswd` or an equivalent tool to manage entries. Do not protect the whole virtual host when secret links are enabled.

The sub-URL examples assume Tinymash is already working below a browser-visible prefix such as `/tinymash`. They only scope authentication; they do not configure sub-URL hosting or rewriting.

## Nginx: top-level installation

Put the `map` in Nginx's `http` context, outside the `server` block:

```nginx
map $uri $tinymash_admin_realm {
    default off;
    ~^/admin(?:/|$) "Tinymash admin";
}
```

Add these directives to the existing Tinymash `server` block. They inherit into its locations without replacing the front-controller rules:

```nginx
auth_basic $tinymash_admin_realm;
auth_basic_user_file /etc/tinymash/admin.htpasswd;
```

## Nginx: installation below a sub-URL

Use the browser-visible prefix in the map. Replace `/tinymash` with the installation's actual URL prefix:

```nginx
map $uri $tinymash_admin_realm {
    default off;
    ~^/tinymash/admin(?:/|$) "Tinymash admin";
}
```

The existing `server` block uses the same two directives:

```nginx
auth_basic $tinymash_admin_realm;
auth_basic_user_file /etc/tinymash/admin.htpasswd;
```

Use only one `map` for a given installation. If another `location` explicitly sets `auth_basic`, verify that it does not override this policy for the admin URL.

## Apache: top-level installation

Add this block inside the existing Tinymash `VirtualHost`:

```apache
<LocationMatch "^/admin(?:/|$)">
    AuthType Basic
    AuthName "Tinymash admin"
    AuthBasicProvider file
    AuthUserFile /etc/tinymash/admin.htpasswd
    Require valid-user
</LocationMatch>
```

## Apache: installation below a sub-URL

Use the browser-visible prefix in `LocationMatch`. Replace `/tinymash` with the installation's actual URL prefix:

```apache
<LocationMatch "^/tinymash/admin(?:/|$)">
    AuthType Basic
    AuthName "Tinymash admin"
    AuthBasicProvider file
    AuthUserFile /etc/tinymash/admin.htpasswd
    Require valid-user
</LocationMatch>
```

After reloading the web server, verify all of these separately:

1. the admin URL requires HTTP Basic authentication
2. the Tinymash login is still required after the HTTP Basic prompt
3. the public home page does not require HTTP Basic authentication
4. `/s/<token>` reaches Tinymash without an HTTP Basic prompt
5. a valid site or author secret link can load its permitted content and media
