// filepath: /.eleventy.js
const syntaxHighlight = require("@11ty/eleventy-plugin-syntaxhighlight");
const { IdAttributePlugin } = require("@11ty/eleventy");



module.exports = function (eleventyConfig) {
    // Passthrough copy for CSS files
    eleventyConfig.addPassthroughCopy("css");
    eleventyConfig.addPassthroughCopy("img");
    eleventyConfig.addPlugin(syntaxHighlight);
    // French date filter: e.g., 12 février 2023
    eleventyConfig.addFilter("dateFr", date => new Intl.DateTimeFormat('fr-FR', {
        year: "numeric", month: "long", day: "numeric",
    }).format(date));
    eleventyConfig.addPlugin(IdAttributePlugin);
    return {};
};
