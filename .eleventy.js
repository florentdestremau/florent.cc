// filepath: /.eleventy.js
const syntaxHighlight = require("@11ty/eleventy-plugin-syntaxhighlight");


module.exports = function (eleventyConfig) {
    // Passthrough copy for CSS files
    eleventyConfig.addPassthroughCopy("bundle.css");
    eleventyConfig.addPassthroughCopy("img");
    eleventyConfig.addPlugin(syntaxHighlight);
    return {
    };
};
