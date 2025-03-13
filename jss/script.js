jQuery(document).ready(function($) {
    // Add new voucher value row
    $('.add-voucher-value').on('click', function() {
        var row = $(this).closest('tr');
        var newRow = row.clone();
        newRow.find('.unipin-voucher-select').val('');
        newRow.find('input[type="number"]').val('1');
        newRow.find('.add-voucher-value').removeClass('add-voucher-value').addClass('remove-voucher-value').text('-');
        row.after(newRow);
    });

    // Remove voucher value row
    $(document).on('click', '.remove-voucher-value', function() {
        $(this).closest('tr').remove();
    });

    // Reset UniPin voucher mapping for a specific variation
    $(document).on('click', '.reset-mapping', function() {
        var variationId = $(this).data('variation-id');
        var confirmReset = confirm('Are you sure you want to reset the mapping for this variation?');
        
        if (confirmReset) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'reset_unipin_voucher_mapping',
                    variation_id: variationId
                },
                success: function(response) {
                    location.reload();
                },
                error: function(xhr, status, error) {
                    console.log(error);
                }
            });
        }
    });
});