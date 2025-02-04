// filepath: /.eleventy.js
module.exports = function (eleventyConfig) {
    // Passthrough copy for CSS files
    eleventyConfig.addPassthroughCopy("css");
    return {
    };
};