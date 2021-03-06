/**
 * @class
 * @extends ve.ui.MWTransclusionOutlinePartWidget
 *
 * @constructor
 * @param {ve.dm.MWTransclusionContentModel} content
 */
ve.ui.MWTransclusionOutlineWikitextWidget = function VeUiMWTransclusionOutlineWikitextWidget( content ) {
	// Parent constructor
	ve.ui.MWTransclusionOutlineWikitextWidget.super.call( this, content, {
		icon: 'wikiText',
		label: ve.msg( 'visualeditor-dialog-transclusion-wikitext' )
	} );
};

/* Inheritance */

OO.inheritClass( ve.ui.MWTransclusionOutlineWikitextWidget, ve.ui.MWTransclusionOutlinePartWidget );
