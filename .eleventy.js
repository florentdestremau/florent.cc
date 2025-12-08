// filepath: /.eleventy.js
const syntaxHighlight = require("@11ty/eleventy-plugin-syntaxhighlight");


module.exports = function (eleventyConfig) {
    // Passthrough copy for CSS files
    eleventyConfig.addPassthroughCopy("bundle.css");
    eleventyConfig.addPassthroughCopy("img");
    eleventyConfig.addPlugin(syntaxHighlight);
    // French date filter: e.g., 12 février 2023
    eleventyConfig.addFilter("dateFr", (value) => {
        const date = value instanceof Date ? value : new Date(value);
        try {
            return new Intl.DateTimeFormat('fr-FR', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            }).format(date);
        } catch (e) {
            return '' + value;
        }
    });
    return {
    };
};
