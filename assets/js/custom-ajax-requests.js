
//jQuery( function( $ ) {
(function($, Settings){

    /*$( 'form.checkout' ).on( 'click', '.woocommerce_checkout_place_order', function() {
        alert('MODAL');
    });*/

    $('#confirm-order-button').click(function () { alert('MODAL');
        /*$('#confirm-order-flag').val('');
        $('#place_order').trigger('click');*/
    });
    let payment_response = Settings.payment_response;
    $("form.woocommerce-checkout").on('submit', function() {
        console.log('Settings',payment_response.item, payment_response.status, payment_response.message);
    });


})(jQuery, wc_settings);
//});
