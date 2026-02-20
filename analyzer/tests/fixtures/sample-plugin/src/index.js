import { registerBlockType } from '@wordpress/blocks';
import { addAction, addFilter } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';

registerBlockType( 'sample-plugin/sample-block', {
    edit: function( props ) {
        return null;
    },
    save: function() {
        return null;
    },
} );

addAction( 'sample_plugin.init', 'sample-plugin/index', function() {
    console.log( 'Plugin initialized' );
} );

addFilter( 'sample_plugin.content', 'sample-plugin/index', function( content ) {
    return content;
} );

async function fetchItems() {
    const items = await apiFetch( { path: '/sample/v1/items', method: 'GET' } );
    return items;
}

async function saveItem( data ) {
    return await apiFetch( {
        path: '/sample/v1/items',
        method: 'POST',
        data,
    } );
}
