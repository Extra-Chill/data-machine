const CSSValueParser = require('postcss-value-parser')

/**
 * @type {import('postcss').PluginCreator}
 */
module.exports = (opts) => {

  const DEFAULTS = {
    skipHostRelativeUrls: true,
  }
  const config = Object.assign(DEFAULTS, opts)

  return {
    postcssPlugin: 'rebaseUrl',

    Declaration(decl) {
      // The faster way to find Declaration node
      const parsedValue = CSSValueParser(decl.value)

      let valueChanged = false
      parsedValue.walk(node => {
        if (node.type !== 'function' || node.value !== 'url') {
          return
        }

        const urlVal = node.nodes[0].value

        // bases relative URLs with rootUrl
        const basedUrl = new URL(urlVal, opts.rootUrl)

        // skip host-relative, already normalized URLs (e.g. `/images/image.jpg`, without `..`s)
        if ((basedUrl.pathname === urlVal) && config.skipHostRelativeUrls) {
          return false // skip this value
        }

        node.nodes[0].value = basedUrl.toString()
        valueChanged = true

        return false // do not walk deeper
      })

      if (valueChanged) {
        decl.value = CSSValueParser.stringify(parsedValue)
      }

    }
  }
}

module.exports.postcss = true
