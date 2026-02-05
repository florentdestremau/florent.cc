// filepath: /.eleventy.js
const syntaxHighlight = require("@11ty/eleventy-plugin-syntaxhighlight");
const { IdAttributePlugin } = require("@11ty/eleventy");



module.exports = function (eleventyConfig) {
    // Passthrough copy for CSS files
    eleventyConfig.addPassthroughCopy("bundle.css");
    eleventyConfig.addPassthroughCopy("img");
    eleventyConfig.addPassthroughCopy("robots.txt");
    eleventyConfig.addPlugin(syntaxHighlight);

    // Filter out draft posts from collections
    eleventyConfig.addCollection("posts", function(collectionApi) {
        return collectionApi.getFilteredByTag("posts").filter(post => !post.data.draft);
    });

    // Collection for draft posts only (local development only)
    eleventyConfig.addCollection("drafts", function(collectionApi) {
        const isDev = process.env.ELEVENTY_ENV !== 'production';
        if (!isDev) return [];
        return collectionApi.getFilteredByTag("posts").filter(post => post.data.draft);
    });
    // French date filter: e.g., 12 février 2023
    eleventyConfig.addFilter("dateFr", date => new Intl.DateTimeFormat('fr-FR', {
        year: "numeric", month: "long", day: "numeric",
    }).format(date));

    // ISO date filter for SEO meta tags
    eleventyConfig.addFilter("dateISO", date => {
        if (!date) return '';
        return new Date(date).toISOString();
    });

    // Reading time filter: calculates estimated reading time
    eleventyConfig.addFilter("readingTime", content => {
        if (!content) return '1 min';
        const wordsPerMinute = 200;
        const text = content.replace(/<[^>]*>/g, ''); // Remove HTML tags
        const wordCount = text.split(/\s+/).filter(word => word.length > 0).length;
        const minutes = Math.ceil(wordCount / wordsPerMinute);
        return `${minutes} min`;
    });

    eleventyConfig.addPlugin(IdAttributePlugin);
    return {};
};
