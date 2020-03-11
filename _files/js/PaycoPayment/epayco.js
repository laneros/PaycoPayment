!function($, window, document, _undefined)
{
    "use strict";

    XF.EpaycoPaymentForm = XF.Element.newHandler({

        options: {
            publicKey: null
        },

        epayco: null,
        cardNumber: null,
        cardExpiry: null,
        cardCvc: null,

        processing: null,

        init: function ()
        {
            this.$target.on('submit', XF.proxy(this, 'submit'));
            XF.loadScript('https://s3-us-west-2.amazonaws.com/epayco/v1.0/epayco.min.js', XF.proxy(this, 'postInit'));
        },

        postInit: function()
        {
            if (!this.options.publicKey)
            {
                console.error('El formulario debe contener un atributo data-public-key.');
                return;
            }

            this.epayco = window.ePayco;
            this.epayco.setPublicKey(this.options.publicKey);

            this.cardNumber = document.getElementById('card-number');
            this.cardExpiry = document.getElementById('card-expiry');
            this.cardCvc = document.getElementById('card-cvc');

            payform.cardNumberInput(this.cardNumber);
            payform.expiryInput(this.cardExpiry);
            payform.cvcInput(this.cardCvc);

            var self = this;
            var overlay = this.$target.closest('.overlay-container').data('overlay');
            overlay.on('overlay:hidden', function()
            {
                overlay.destroy();
                delete XF.loadedScripts['https://s3-us-west-2.amazonaws.com/epayco/v1.0/epayco.min.js'];

                payform.detachCardNumberInput(self.cardNumber);
                payform.detachExpiryInput(self.cardExpiry);
                payform.detachCvcInput(self.cardCvc);
            });
        },

        submit: function(e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return false;
            }

            this.processing = true;

            var self = this,
                cardExpiry = payform.parseCardExpiry(this.cardExpiry.value),
                $submit = $(e.target),
                epayco = this.epayco,
                $errorContainer = $('#card-errors-container'),
                $error = $errorContainer.find('#card-errors');

            $submit.addClass('is-disabled')
                .prop('disabed', true);


            $(this.$target).find('#epayco-card-number').val(this.cardNumber.value.replace(/\s+/g,''));
            $(this.$target).find('#epayco-card-month').val(cardExpiry.month);
            $(this.$target).find('#epayco-card-year').val(cardExpiry.year);
            epayco.token.create(this.$target, function(error, token) {
                if(!error) {
                    $errorContainer.addClass('u-hidden');
                    $error.addClass('u-hidden');

                    $error.text('');

                    self.epaycoTokenHandler(token);
                } else {
                    //muestra errores que hayan sucedido en la transacción
                    $error.text(error.description);

                    $error.removeClass('u-hidden');
                    $errorContainer.removeClass('u-hidden');

                    self.processing = false;
                }
            });
        },

        epaycoTokenHandler: function(token)
        {
            var $form = this.$target,
                $input = $('<input type="hidden" />');

            $input.attr('name', 'epaycoToken');
            $input.attr('value', token);

            $form.append($input);

            this.response();
        },

        response: function()
        {
            var $form = this.$target,
                formData = XF.getDefaultFormData($form);

            formData.delete('card-number');
            formData.delete('card-month');
            formData.delete('card-year');
            XF.ajax('post', $form.attr('action'), formData, XF.proxy(this, 'complete'), { skipDefaultSuccess: true });
        },

        complete: function(data)
        {
            this.processing = false;

            if (data.redirect)
            {
                XF.redirect(data.redirect);
            }
        }
    });


    XF.Element.register('epayco-payment-form', 'XF.EpaycoPaymentForm');
}
(jQuery, window, document);