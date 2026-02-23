# PostCSS URL rebase plugin

[PostCSS] plugin for rebasing URLs to a given root URL.

[PostCSS]: https://github.com/postcss/postcss

```css
.foo {
  /* Input example */
  background: url("images/test.jpg");
  mask: url("/images/layout/shape.svg");
}
```

```css
.foo {
  /* Output example */
  background: url("https://example.com/images/test.jpg");
  mask: url("/images/layout/shape.svg");
}
```

## Usage

**Step 1:** Install plugin:

```sh
npm install --save-dev postcss postcss-urlrebase
```

**Step 2:** Check you project for existed PostCSS config: `postcss.config.js`
in the project root, `"postcss"` section in `package.json`
or `postcss` in bundle config.

If you do not use PostCSS, add it according to [official docs]
and set this plugin in settings.

**Step 3:** Add the plugin to plugins list:

```diff
module.exports = {
  plugins: [
+   require('postcss-urlrebase')({ rootUrl: "https://example.com/wp/wp-themes/example/" },
    require('autoprefixer')
  ]
}
```

### Plugin options

#### `rootUrl` (_required_)
The root URL to which the existing URLs should be based to.

#### `skipHostRelativeUrls` (optional (default: `true`))
Should already host-relative URLs (as `/images/test.jpg` (URL starts with `/`)) be skipped?


[official docs]: https://github.com/postcss/postcss#usage
