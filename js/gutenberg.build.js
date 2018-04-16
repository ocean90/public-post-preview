this["publicPostPreview"] = this["publicPostPreview"] || {}; this["publicPostPreview"]["main"] =
/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, {
/******/ 				configurable: false,
/******/ 				enumerable: true,
/******/ 				get: getter
/******/ 			});
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = "./js/gutenberg.js");
/******/ })
/************************************************************************/
/******/ ({

/***/ "./js/components/preview-until/index.js":
/*!**********************************************!*\
  !*** ./js/components/preview-until/index.js ***!
  \**********************************************/
/*! exports provided: PreviewUntil */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
eval("__webpack_require__.r(__webpack_exports__);\n/* harmony export (binding) */ __webpack_require__.d(__webpack_exports__, \"PreviewUntil\", function() { return PreviewUntil; });\n/* harmony import */ var _wordpress_date__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/date */ \"@wordpress/date\");\n/* harmony import */ var _wordpress_date__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_date__WEBPACK_IMPORTED_MODULE_0__);\n/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ \"@wordpress/components\");\n/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);\n\n\n\nfunction PreviewUntil(_ref) {\n\tvar date = _ref.date,\n\t    onUpdateDate = _ref.onUpdateDate;\n\n\t// To know if the current timezone is a 12 hour time with look for \"a\" in the time format\n\t// We also make sure this a is not escaped by a \"/\"\n\tvar is12HourTime = /a(?!\\\\)/i.test(_wordpress_date__WEBPACK_IMPORTED_MODULE_0__[\"settings\"].formats.time.toLowerCase() // Test only the lower case a\n\t.replace(/\\\\\\\\/g, '') // Replace \"//\" with empty strings\n\t.split('').reverse().join('') // Reverse the string and test for \"a\" not followed by a slash\n\t);\n\n\treturn wp.element.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__[\"DateTimePicker\"], {\n\t\tkey: 'date-time-picker',\n\t\tcurrentDate: date,\n\t\tonChange: onUpdateDate,\n\t\tlocale: _wordpress_date__WEBPACK_IMPORTED_MODULE_0__[\"settings\"].l10n.locale,\n\t\tis12Hour: is12HourTime\n\t});\n}\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/./js/components/preview-until/index.js?");

/***/ }),

/***/ "./js/gutenberg.js":
/*!*************************!*\
  !*** ./js/gutenberg.js ***!
  \*************************/
/*! no exports provided */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
eval("__webpack_require__.r(__webpack_exports__);\n/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ \"@wordpress/i18n\");\n/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);\n/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ \"@wordpress/element\");\n/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);\n/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ \"@wordpress/components\");\n/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);\n/* harmony import */ var _wordpress_edit_post__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/edit-post */ \"@wordpress/edit-post\");\n/* harmony import */ var _wordpress_edit_post__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_edit_post__WEBPACK_IMPORTED_MODULE_3__);\n/* harmony import */ var _wordpress_plugins__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! @wordpress/plugins */ \"@wordpress/plugins\");\n/* harmony import */ var _wordpress_plugins__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(_wordpress_plugins__WEBPACK_IMPORTED_MODULE_4__);\n/* harmony import */ var _components_preview_until__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./components/preview-until */ \"./js/components/preview-until/index.js\");\n/* harmony import */ var _wordpress_date__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! @wordpress/date */ \"@wordpress/date\");\n/* harmony import */ var _wordpress_date__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(_wordpress_date__WEBPACK_IMPORTED_MODULE_6__);\n\n\n\n\n\n\n\n\n// Destructure experimental components.\nvar PluginSidebar = _wordpress_edit_post__WEBPACK_IMPORTED_MODULE_3__[\"__experimental\"].PluginSidebar,\n    PluginMoreMenuItem = _wordpress_edit_post__WEBPACK_IMPORTED_MODULE_3__[\"__experimental\"].PluginMoreMenuItem;\n\n\nvar previewStatus = true;\nvar previewUntil = new Date();\n\nvar Component = function Component() {\n\treturn wp.element.createElement(\n\t\t_wordpress_element__WEBPACK_IMPORTED_MODULE_1__[\"Fragment\"],\n\t\tnull,\n\t\twp.element.createElement(\n\t\t\tPluginMoreMenuItem,\n\t\t\t{\n\t\t\t\tname: 'public-post-preview',\n\t\t\t\ttype: 'sidebar',\n\t\t\t\ttarget: 'public-post-preview'\n\t\t\t},\n\t\t\tObject(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__[\"__\"])('Public Post Preview', 'public-post-preview')\n\t\t),\n\t\twp.element.createElement(\n\t\t\tPluginSidebar,\n\t\t\t{\n\t\t\t\tname: 'public-post-preview',\n\t\t\t\ttitle: Object(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__[\"__\"])('Public Post Preview', 'public-post-preview')\n\t\t\t},\n\t\t\twp.element.createElement(\n\t\t\t\t_wordpress_components__WEBPACK_IMPORTED_MODULE_2__[\"Panel\"],\n\t\t\t\tnull,\n\t\t\t\twp.element.createElement(\n\t\t\t\t\t_wordpress_components__WEBPACK_IMPORTED_MODULE_2__[\"PanelBody\"],\n\t\t\t\t\tnull,\n\t\t\t\t\twp.element.createElement(\n\t\t\t\t\t\t_wordpress_components__WEBPACK_IMPORTED_MODULE_2__[\"PanelRow\"],\n\t\t\t\t\t\tnull,\n\t\t\t\t\t\twp.element.createElement(\n\t\t\t\t\t\t\t'label',\n\t\t\t\t\t\t\t{ htmlFor: 'public-post-preview-status' },\n\t\t\t\t\t\t\tObject(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__[\"__\"])('Enable', 'public-post-preview')\n\t\t\t\t\t\t),\n\t\t\t\t\t\twp.element.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__[\"FormToggle\"], {\n\t\t\t\t\t\t\tchecked: previewStatus,\n\t\t\t\t\t\t\tshowHint: false,\n\t\t\t\t\t\t\tonChange: function onChange() {\n\t\t\t\t\t\t\t\treturn previewStatus = !previewStatus;\n\t\t\t\t\t\t\t},\n\t\t\t\t\t\t\tid: 'public-post-preview-status'\n\t\t\t\t\t\t})\n\t\t\t\t\t),\n\t\t\t\t\tpreviewStatus && [wp.element.createElement(\n\t\t\t\t\t\t_wordpress_components__WEBPACK_IMPORTED_MODULE_2__[\"PanelRow\"],\n\t\t\t\t\t\t{ key: 'foobar' },\n\t\t\t\t\t\twp.element.createElement(\n\t\t\t\t\t\t\t'span',\n\t\t\t\t\t\t\tnull,\n\t\t\t\t\t\t\tObject(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__[\"__\"])('Valid until', 'public-post-preview')\n\t\t\t\t\t\t),\n\t\t\t\t\t\twp.element.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__[\"Dropdown\"], {\n\t\t\t\t\t\t\tposition: 'bottom left',\n\t\t\t\t\t\t\tcontentClassName: 'edit-post-post-schedule__dialog',\n\t\t\t\t\t\t\trenderToggle: function renderToggle(_ref) {\n\t\t\t\t\t\t\t\tvar onToggle = _ref.onToggle,\n\t\t\t\t\t\t\t\t    isOpen = _ref.isOpen;\n\t\t\t\t\t\t\t\treturn wp.element.createElement(\n\t\t\t\t\t\t\t\t\t'button',\n\t\t\t\t\t\t\t\t\t{\n\t\t\t\t\t\t\t\t\t\ttype: 'button',\n\t\t\t\t\t\t\t\t\t\tclassName: 'button-link',\n\t\t\t\t\t\t\t\t\t\tonClick: onToggle,\n\t\t\t\t\t\t\t\t\t\t'aria-expanded': isOpen\n\t\t\t\t\t\t\t\t\t},\n\t\t\t\t\t\t\t\t\tObject(_wordpress_date__WEBPACK_IMPORTED_MODULE_6__[\"dateI18n\"])(_wordpress_date__WEBPACK_IMPORTED_MODULE_6__[\"settings\"].formats.datetime, previewUntil)\n\t\t\t\t\t\t\t\t);\n\t\t\t\t\t\t\t},\n\t\t\t\t\t\t\trenderContent: function renderContent() {\n\t\t\t\t\t\t\t\treturn wp.element.createElement(_components_preview_until__WEBPACK_IMPORTED_MODULE_5__[\"PreviewUntil\"], {\n\t\t\t\t\t\t\t\t\tdate: previewUntil,\n\t\t\t\t\t\t\t\t\tonUpdateDate: function onUpdateDate(date) {\n\t\t\t\t\t\t\t\t\t\treturn previewUntil = date;\n\t\t\t\t\t\t\t\t\t}\n\t\t\t\t\t\t\t\t});\n\t\t\t\t\t\t\t}\n\t\t\t\t\t\t})\n\t\t\t\t\t), wp.element.createElement(\n\t\t\t\t\t\t_wordpress_components__WEBPACK_IMPORTED_MODULE_2__[\"PanelRow\"],\n\t\t\t\t\t\t{ key: 'foobarfoo' },\n\t\t\t\t\t\twp.element.createElement(\n\t\t\t\t\t\t\t'label',\n\t\t\t\t\t\t\t{ htmlFor: 'public-post-preview-url' },\n\t\t\t\t\t\t\tObject(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__[\"__\"])('URL', 'public-post-preview')\n\t\t\t\t\t\t),\n\t\t\t\t\t\twp.element.createElement('input', { type: 'text', id: 'public-post-preview-url', value: 'http://src.wp.test/?p=yolo', readOnly: true })\n\t\t\t\t\t)]\n\t\t\t\t)\n\t\t\t)\n\t\t)\n\t);\n};\n\nObject(_wordpress_plugins__WEBPACK_IMPORTED_MODULE_4__[\"registerPlugin\"])('public-post-preview', {\n\trender: Component\n});\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/./js/gutenberg.js?");

/***/ }),

/***/ "@wordpress/components":
/*!*********************************************!*\
  !*** external {"this":["wp","components"]} ***!
  \*********************************************/
/*! no static exports found */
/***/ (function(module, exports) {

eval("(function() { module.exports = this[\"wp\"][\"components\"]; }());\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/external_%7B%22this%22:%5B%22wp%22,%22components%22%5D%7D?");

/***/ }),

/***/ "@wordpress/date":
/*!***************************************!*\
  !*** external {"this":["wp","date"]} ***!
  \***************************************/
/*! no static exports found */
/***/ (function(module, exports) {

eval("(function() { module.exports = this[\"wp\"][\"date\"]; }());\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/external_%7B%22this%22:%5B%22wp%22,%22date%22%5D%7D?");

/***/ }),

/***/ "@wordpress/edit-post":
/*!*******************************************!*\
  !*** external {"this":["wp","editPost"]} ***!
  \*******************************************/
/*! no static exports found */
/***/ (function(module, exports) {

eval("(function() { module.exports = this[\"wp\"][\"editPost\"]; }());\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/external_%7B%22this%22:%5B%22wp%22,%22editPost%22%5D%7D?");

/***/ }),

/***/ "@wordpress/element":
/*!******************************************!*\
  !*** external {"this":["wp","element"]} ***!
  \******************************************/
/*! no static exports found */
/***/ (function(module, exports) {

eval("(function() { module.exports = this[\"wp\"][\"element\"]; }());\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/external_%7B%22this%22:%5B%22wp%22,%22element%22%5D%7D?");

/***/ }),

/***/ "@wordpress/i18n":
/*!***************************************!*\
  !*** external {"this":["wp","i18n"]} ***!
  \***************************************/
/*! no static exports found */
/***/ (function(module, exports) {

eval("(function() { module.exports = this[\"wp\"][\"i18n\"]; }());\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/external_%7B%22this%22:%5B%22wp%22,%22i18n%22%5D%7D?");

/***/ }),

/***/ "@wordpress/plugins":
/*!******************************************!*\
  !*** external {"this":["wp","plugins"]} ***!
  \******************************************/
/*! no static exports found */
/***/ (function(module, exports) {

eval("(function() { module.exports = this[\"wp\"][\"plugins\"]; }());\n\n//# sourceURL=webpack://publicPostPreview.%5Bname%5D/external_%7B%22this%22:%5B%22wp%22,%22plugins%22%5D%7D?");

/***/ })

/******/ });