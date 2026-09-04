import { QuartzConfig } from "./quartz/cfg"
import * as Plugin from "./quartz/plugins"

/**
 * Quartz 4 configuration for "Mara's Garden".
 *
 * This file is the illustrative source-of-truth a real Quartz build would read.
 * Run `npx quartz build --serve` to regenerate the static HTML in this repo from
 * the markdown in `notes/`. The committed *.html files are the produced output.
 */
const config: QuartzConfig = {
  configuration: {
    pageTitle: "🌱 Mara's Garden",
    pageTitleSuffix: " — a digital garden",
    enableSPA: true,
    enablePopovers: true, // hover-preview of [[wikilinks]]
    analytics: null,
    locale: "en-US",
    baseUrl: "garden.maraokonkwo.dev",
    ignorePatterns: ["private", "templates", ".obsidian", "drafts/**"],
    defaultDateType: "modified",
    theme: {
      fontOrigin: "googleFonts",
      cdnCaching: true,
      typography: {
        header: "Fraunces",
        body: "Source Serif 4",
        code: "JetBrains Mono",
      },
      colors: {
        lightMode: {
          light: "#fbf9f3",
          lightgray: "#e8e3d6",
          gray: "#b9b3a3",
          darkgray: "#4b4a44",
          dark: "#2b2a26",
          secondary: "#3a7d52", // garden green
          tertiary: "#84a98c",
          highlight: "rgba(58, 125, 82, 0.12)",
          textHighlight: "#e7c44688",
        },
        darkMode: {
          light: "#16181a",
          lightgray: "#2a2d30",
          gray: "#4e5358",
          darkgray: "#cfd2d4",
          dark: "#e6e8ea",
          secondary: "#6fbf8b",
          tertiary: "#9bcfa9",
          highlight: "rgba(111, 191, 139, 0.15)",
          textHighlight: "#b3a02a55",
        },
      },
    },
  },
  plugins: {
    transformers: [
      Plugin.FrontMatter(),
      Plugin.CreatedModifiedDate({ priority: ["frontmatter", "git", "filesystem"] }),
      Plugin.SyntaxHighlighting({ theme: { light: "github-light", dark: "github-dark" } }),
      Plugin.ObsidianFlavoredMarkdown({ enableInHtmlEmbed: false }),
      Plugin.GitHubFlavoredMarkdown(),
      Plugin.TableOfContents(),
      Plugin.CrawlLinks({ markdownLinkResolution: "shortest" }),
      Plugin.Description(),
      Plugin.Latex({ renderEngine: "katex" }),
    ],
    filters: [Plugin.RemoveDrafts()],
    emitters: [
      Plugin.AliasRedirects(),
      Plugin.ComponentResources(),
      Plugin.ContentPage(),
      Plugin.FolderPage(),
      Plugin.TagPage(),
      Plugin.ContentIndex({ enableSiteMap: true, enableRSS: true }),
      Plugin.Assets(),
      Plugin.Static(),
      Plugin.NotFoundPage(),
    ],
  },
}

export default config
