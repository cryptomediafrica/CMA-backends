/*
 * ==========================================================
 * ADMINISTRATION SCRIPT
 * ==========================================================
 *
 * Main Javascript admin file. © 2022 boxcoin.dev. All rights reserved.
 *  
 */

'use strict';
(function () {

    var body;
    var main;
    var timeout;
    var areas;
    var active_area;
    var active_area_id;
    var active_element;
    var transactions_table;
    var transactions_filters = [false, false, false, false];
    var pagination = 0;
    var pagination_count = true;
    var today = new Date();
    var BXC_CHECKOUTS = false;
    var responsive = window.innerWidth < 769;
    var datepicker = false;
    var WP;
    var _ = window._query;

    /*
    * ----------------------------------------------------------
    * BXCTransactions
    * ----------------------------------------------------------
    */

    var BXCTransactions = {

        get: function (onSuccess, search = false, status = false, cryptocurrency = false, date_range = false) {
            ajax('get-transactions', { pagination: pagination, search: search, status: status, cryptocurrency: cryptocurrency, date_range: date_range }, (response) => {
                pagination++;
                onSuccess(response);
            });
        },

        print: function (onSuccess, search = transactions_filters[0], status = transactions_filters[1], cryptocurrency = transactions_filters[2], date_range = transactions_filters[3]) {
            this.get((response) => {
                let code = '';
                pagination_count = response.length;
                for (var i = 0; i < pagination_count; i++) {
                    let transaction = response[i];
                    let from = transaction.from ? `<a href="${BXCAdmin.explorer(transaction.cryptocurrency, transaction.from)}" target="_blank" class="bxc-link">${transaction.from}</a>` : '';
                    code += `<tr data-id="${transaction.id}" data-cryptocurrency="${transaction.cryptocurrency}" data-hash="${transaction.hash}"><td class="bcx-td-time"><div class="bxc-title">${BOXCoin.beautifyTime(transaction.creation_time, true)}</div></td><td class="bxc-td-title"><div class="bxc-title">${transaction.title}</div><div class="bxc-text">${transaction.description}</div></td><td>${from}</td><td><a href="${BXCAdmin.explorer(transaction.cryptocurrency, transaction.to)}" target="_blank" class="bxc-link">${transaction.to}</a></td><td class="bxc-td-status"><span class="bxc-status-${transaction.status}">${bxc_(transaction.status == 'C' ? 'Completed' : 'Pending')}</span></td><td class="bxc-td-amount"><div class="bxc-title">${transaction.amount} ${transaction.cryptocurrency.toUpperCase()}</div><div class="bxc-text">${transaction.currency.toUpperCase()} ${transaction.amount_fiat}</div></td></tr>`;
                }
                print(transactions_table.find('tbody'), code, true);
                if (onSuccess) onSuccess(response);
            }, search, status, cryptocurrency, date_range);
        },

        query: function (icon = false) {
            if (loading(transactions_table)) return;
            transactions_table.find('tbody').html('');
            pagination = 0;
            transactions_filters[0] = _(main).find('#bxc-search-transactions').val().toLowerCase().trim();
            transactions_filters[1] = _(main).find('#bxc-filter-status li.bxc-active').attr('data-value');
            transactions_filters[2] = _(main).find('#bxc-filter-cryptocurrency li.bxc-active').attr('data-value');
            transactions_filters[3] = datepicker ? datepicker.getDates('yyyy-mm-dd') : false;
            this.print(() => {
                if (icon) loading(icon, false);
                loading(transactions_table, false);
            });
        },

        download: function (onSuccess) {
            ajax('download-transactions', { search: transactions_filters[0], status: transactions_filters[1], cryptocurrency: transactions_filters[2], date_range: transactions_filters[3] }, (response) => {
                onSuccess(response);
            });
        }
    }

    /*
    * ----------------------------------------------------------
    * BXCCheckout
    * ----------------------------------------------------------
    */

    var BXCCheckout = {

        row: function (checkout) {
            return `<tr data-checkout-id="${checkout.id}"><td><div class="bxc-title"><span>${checkout.id}</span><span>${checkout.title}</span></div></td><td><div class="bxc-text">${checkout.currency ? checkout.currency : BXC_CURRENCY} ${checkout.price}</div></td></tr>`;
        },

        embed: function (id = false) {
            let index = WP ? 3 : 2;
            for (var i = 0; i < index; i++) {
                let elements = active_area.find('#bxc-checkout-' + (i == 0 ? 'payment-link' : (i == 1 ? 'embed-code' : 'shortcode'))).find('div, i');
                if (id) {
                    let content = i == 0 ? `${BXC_URL}pay.php?checkout_id=${id}` : (i == 1 ? `<div data-boxcoin="${id}"></div>` : `[boxcoin id="${id}"]`);
                    _(elements.e[0]).html(content.replace(/</g, '&lt;'));
                    _(elements.e[1]).attr('data-text', window.btoa(content));
                } else {
                    _(elements.e[0]).html('');
                    _(elements.e[1]).attr('data-text', '');
                }
            }
        },

        get: function (id, remove = false) {
            for (var i = 0; i < BXC_CHECKOUTS.length; i++) {
                if (id == BXC_CHECKOUTS[i].id) {
                    if (remove) {
                        BXC_CHECKOUTS.splice(i, 1);
                        return true;
                    }
                    return BXC_CHECKOUTS[i];
                }
            }
            return false;
        }
    }

    /*
    * ----------------------------------------------------------
    * BXCAdmin
    * ----------------------------------------------------------
    */

    var BXCAdmin = {

        card: function (message, type = false) {
            var card = main.find('.bxc-info-card');
            if (!type) {
                card.removeClass('bxc-info-card-error bxc-info-card-warning bxc-info-card-info');
                clearTimeout(timeout);
            } else if (type == 'error') {
                card.addClass('bxc-info-card-error');
            } else {
                card.addClass('bxc-info-card-info');
            }
            card.html(bxc_(message));
            timeout = setTimeout(() => { card.html('') }, 5000);
        },

        error: function (message, loading_area = false) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            main.find('.bxc-info').html(message);
            if (loading_area) loading(loading_area, false);
        },

        balance: function (area) {
            if (!loading(area)) {
                ajax('get-balances', {}, (response) => {
                    let code = '';
                    let balances = response.balances;
                    main.find('#bxc-balance-total').html(`${BXC_CURRENCY} ${response.total}`);
                    for (var key in balances) {
                        code += `<tr data-cryptocurrency="${key}"><td><div class="bxc-flex"><img src="${BXC_URL}media/icon-${key}.svg" /> ${balances[key].name}</div></td><td><div class="bxc-balance bxc-title">${balances[key].amount} ${key.toUpperCase()}</div><div class="bxc-text">${BXC_CURRENCY} ${balances[key].fiat}</div></td></tr>`;
                    }
                    main.find('#bxc-table-balances tbody').html(code);
                    loading(area, false);
                });
            }
        },

        explorer: function (cryptocurrency, value, type = 'address') {
            let explorer = '';
            let replace = type == 'address' ? 'address' : 'tx';
            switch (cryptocurrency) {
                case 'btc':
                    explorer = 'https://www.blockchain.com/btc/{R}/{V}';
                    break;
                case 'link':
                case 'bat':
                case 'shib':
                case 'usdc':
                case 'usdt':
                case 'eth':
                    explorer = 'https://www.blockchain.com/eth/{R}/{V}';
                    break;
                case 'doge':
                    explorer = 'https://dogechain.info/{R}/{V}';
                    break;
                case 'algo':
                    explorer = 'https://algoexplorer.io/{R}/{V}';
                    break;
            }
            return explorer.replace('{R}', replace).replace('{V}', value);
        }
    }

    window.BXCTransactions = BXCTransactions;
    window.BXCCheckout = BXCCheckout;
    window.BXCAdmin = BXCAdmin;

    /*
    * ----------------------------------------------------------
    * Functions
    * ----------------------------------------------------------
    */

    function loading(element, action = -1) {
        return BOXCoin.loading(element, action);
    }

    function ajax(function_name, data = {}, onSuccess = false) {
        return BOXCoin.ajax(function_name, data, onSuccess);
    }

    function activate(element, activate = true) {
        return BOXCoin.activate(element, activate);
    }

    function card(message, type = false) {
        BXCAdmin.card(message, type);
    }

    function bxc_(text) {
        return BXC_TRANSLATIONS && text in BXC_TRANSLATIONS ? BXC_TRANSLATIONS[text] : text;
    }

    function showError(message, loading_area = false) {
        BXCAdmin.error(message, loading_area);
    }

    function scrollBottom() {
        window.scrollTo(0, document.body.scrollHeight - 800);
    }

    function inputValue(input, value = -1) {
        if (!input || !_(input).length()) return '';
        input = _(input).e[0];
        if (value === -1) return _(input).is('checkbox') ? input.checked : input.value.trim();
        if (_(input).is('checkbox')) {
            input.checked = value;
        } else {
            input.value = value;
        }
    }

    function inputGet(parent) {
        return _(parent).find('input, select, textarea');
    }

    function openURL() {
        let url = window.location.href;
        if (url.includes('#')) {
            let anchor = url.substr(url.indexOf('#'));
            if (anchor.length > 1) {
                let item = main.find('.bxc-nav ' + anchor);
                if (item.length) {
                    nav(item);
                    return true;
                }
            }
        }
        return false;
    }

    function nav(nav_item) {
        let items = main.find('main > div');
        let index = nav_item.index();
        active_area = _(items.e[index]);
        active_area_id = nav_item.attr('id');
        main.removeClass('bxc-area-transactions bxc-area-checkouts bxc-area-balances bxc-area-settings').addClass('bxc-area-' + active_area_id);
        activate(items, false);
        activate(nav_item.siblings(), false);
        activate(nav_item);
        activate(active_area);
        if (!window.location.href.includes(active_area_id)) window.history.pushState('', '', '#' + active_area_id);
        switch (active_area_id) {
            case 'transactions':
                if (!loading(active_area)) {
                    loading(active_area);
                    pagination = 0;
                    transactions_table.find('tbody').html('');
                    BXCTransactions.print(() => { loading(items.e[index], false) });
                }
                break;
            case 'checkouts':
                if (active_area.hasClass('bxc-loading')) {
                    ajax('get-checkouts', {}, (response) => {
                        let code = '';
                        BXC_CHECKOUTS = response;
                        for (var i = 0; i < response.length; i++) {
                            code += BXCCheckout.row(response[i]);
                        }
                        print(main.find('#bxc-table-checkouts tbody'), code);
                        loading(items.e[index], false);
                    });
                }
                break;
            case 'balances':
                BXCAdmin.balance(active_area);
                break;
            case 'settings':
                if (active_area.hasClass('bxc-loading')) {
                    ajax('get-settings', {}, (response) => {
                        for (var key in response) {
                            inputValue(inputGet(main.find(`#${key}`)), response[key]);
                        }
                        loading(items.e[index], false);
                    });
                }
                break;
        }
    }

    function slugToString(string) {
        string = string.replace(/_/g, ' ').replace(/-/g, ' ');
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    function print(area, code, append = false) {
        if (!code) return area.html() ? false : area.html(`<p id="bxc-not-found">${bxc_('There\'s nothing here yet.')}</p>`);
        area.find('#bxc-not-found').remove();
        area.html((append ? area.html() : '') + code);
    }

    document.addEventListener('DOMContentLoaded', () => {

        /*
        * ----------------------------------------------------------
        * # Init
        * ----------------------------------------------------------
        */

        body = _(document.body);
        main = body.find('.bxc-main');
        areas = main.find('main > div');
        active_area = areas.e[0];
        transactions_table = main.find('#bxc-table-transactions');
        WP = typeof BXC_WP != 'undefined';
        if (BOXCoin.cookie('BXC_LOGIN') && !main.hasClass('bxc-installation')) {
            active_area_id = 'transactions';
            if (!openURL()) {
                BXCTransactions.print(() => { loading(active_area, false) });
            }
            let cron = localStorage.getItem('bxc-cron');
            if (!cron || cron != today.getDate()) {
                ajax('cron');
                localStorage.setItem('bxc-cron', today.getDate());
            }
            BXCAdmin.balance(main.find('main > [data-area="balance"]'));
        }

        /*
        * ----------------------------------------------------------
        * Transactions
        * ----------------------------------------------------------
        */

        transactions_table.on('click', 'tr', function (e) {
            if (_(e.target).is('a')) return;
            let hash = _(this).attr('data-hash');
            if (hash) {
                let cryptocurrency = _(this).attr('data-cryptocurrency');
                window.open(BXCAdmin.explorer(cryptocurrency, hash, 'tx'));
            }
        });

        main.on('click', '#bxc-filters', function () {
            main.find('.bxc-nav-filters').toggleClass('bxc-active');
        });

        main.on('click', '#bxc-filter-date,#bxc-filter-date-2', function () {
            let settings = {
                maxNumberOfDates: 2,
                maxDate: new Date(),
                dateDelimiter: ' - ',
                clearBtn: true
            };
            if (!datepicker) {
                _.load(BXC_URL + 'vendor/datepicker/datepicker.min.css', false);
                _.load(BXC_URL + 'vendor/datepicker/datepicker.min.js', true, () => {
                    if (BXC_LANG) {
                        settings.language = BXC_LANG;
                        _.load(`${BXC_URL}vendor/datepicker/locales/${BXC_LANG}.js`, true, () => {
                            datepicker = new DateRangePicker(_(this).parent().e[0], settings);
                            datepicker.datepickers[0].show();
                        });
                    } else {
                        datepicker = new DateRangePicker(_(this).parent().e[0], settings);
                        datepicker.datepickers[0].show();
                    }
                });
            }
        });

        main.on('click', '#bxc-filter-status li,#bxc-filter-cryptocurrency li, .datepicker-cell, .datepicker .clear-btn', function () {
            setTimeout(() => { BXCTransactions.query() }, 100);
        });

        main.on('click', '#bxc-download-transitions', function () {
            if (loading(this)) return;
            BXCTransactions.download((response) => {
                window.open(response);
                loading(this, false);
            });
        });

        /*
        * ----------------------------------------------------------
        * Checkouts
        * ----------------------------------------------------------
        */

        main.on('click', '#bxc-create-checkout, #bxc-table-checkouts td', function () {
            main.addClass('bxc-area-create-checkout');
            if (_(this).is('td')) {
                let id = _(this).parent().attr('data-checkout-id');
                let checkout = BXCCheckout.get(id);
                active_area.attr('data-checkout-id', id);
                for (var key in checkout) {
                    let input = inputGet(active_area.find(`#bxc-checkout-${key}`));
                    let value = checkout[key];
                    inputValue(input, value);
                }
                BXCCheckout.embed(id);
            } else {
                inputGet(active_area).e.forEach(e => {
                    inputValue(e, '');
                });
                active_area.find('#bxc-checkout-type select').val('I');
                active_area.attr('data-checkout-id', '');
                BXCCheckout.embed();
            }
        });

        main.on('click', '#bxc-checkouts-list', function () {
            main.removeClass('bxc-area-create-checkout');
            active_area.attr('data-checkout-id', '');
        });

        main.on('click', '#bxc-save-checkout', function () {
            if (loading(this)) return;
            let error = false;
            let checkout = {};
            let inputs = active_area.find('.bxc-input');
            let checkout_id = active_area.attr('data-checkout-id');
            main.find('.bxc-info').html('');
            inputs.removeClass('bxc-error');
            inputs.e.forEach(e => {
                let id = _(e).attr('id');
                let input = _(e).find('input, select');
                let value = inputValue(input);
                if (!value && input.length() && input.attr('required')) {
                    error = true;
                    _(e).addClass('bxc-error');
                }
                checkout[id.replace('bxc-checkout-', '')] = value;
            });
            if (error) {
                showError('Fields in red are required.', this);
                return;
            }
            if (checkout_id) checkout['id'] = checkout_id;
            ajax('save-checkout', { checkout: checkout }, (response) => {
                loading(this, false);
                if (Number.isInteger(response)) {
                    checkout['id'] = response;
                    active_area.attr('data-checkout-id', response);
                    active_area.find('#bxc-table-checkouts tbody').append(BXCCheckout.row(checkout));
                    BXCCheckout.embed(response);
                    BXC_CHECKOUTS.push(checkout);
                    card('Checkout saved successfully');
                } else if (response === true) {
                    BXCCheckout.get(checkout_id, true);
                    BXC_CHECKOUTS.push(checkout);
                    active_area.find(`tr[data-checkout-id="${checkout_id}"]`).replace(BXCCheckout.row(checkout));
                    card('Checkout saved successfully');
                } else {
                    showError(response, this.closest('form'));
                }
            });
        });

        main.on('click', '#bxc-delete-checkout', function () {
            if (loading(this)) return;
            let id = active_area.attr('data-checkout-id');
            ajax('delete-checkout', { checkout_id: id }, () => {
                loading(this, false);
                active_area.attr('data-checkout-id', '');
                active_area.find(`tr[data-checkout-id="${id}"]`).remove();
                active_area.find('#bxc-checkouts-list').e[0].click();
                card('Checkout deleted', 'error');
            });
        });

        /*
        * ----------------------------------------------------------
        * Settings
        * ----------------------------------------------------------
        */

        main.on('click', '#bxc-save-settings', function () {
            if (loading(this)) return;
            let settings = {};
            main.find('[data-area="settings"]').find('.bxc-input[id]:not([data-type="multi-input"]),[data-type="multi-input"] [id]').e.forEach(e => {
                settings[_(e).attr('id')] = inputValue(inputGet(e));
            });
            ajax('save-settings', { settings: JSON.stringify(settings) }, (response) => {
                card(response === true ? 'Settings saved' : response, response === true ? false : 'error');
                loading(this, false);
            });
        });

        main.on('click', '#update-btn a', function (e) {
            if (loading(this)) return;
            ajax('update', { domain: BXC_URL }, (response) => {
                if (response === true) {
                    card('Update completed. Reload in progress...');
                    setTimeout(() => { location.reload(); }, 500);
                } else if (response == 'latest-version-installed') {
                    card('You have the latest version');
                } else {
                    card(slugToString(response), 'error');
                }
                loading(this, false);
            });
            e.preventDefault();
            return false;
        });

        /*
        * ----------------------------------------------------------
        * Responsive
        * ----------------------------------------------------------
        */

        if (responsive) {
            main.on('click', '.bxc-icon-menu', function () {
                let area = _(this).parent();
                activate(area, !area.hasClass('bxc-active'));
            });
        }

        /*
        * ----------------------------------------------------------
        * Miscellaneous
        * ----------------------------------------------------------
        */

        main.on('click', '#bxc-submit-installation', function () {
            if (loading(this)) return;
            let error = false;
            let installation_data = {};
            let url = window.location.href.replace('/admin', '').replace('.php', '').replace(/#$|\/$/, '');
            let inputs = main.find('.bxc-input');
            inputs.removeClass('bxc-error');
            main.find('.bxc-info').html('');
            inputs.e.forEach(e => {
                let id = _(e).attr('id');
                let input = _(e).find('input');
                let value = input.val().trim();
                if (!value && input.attr('required')) {
                    error = true;
                    _(e).addClass('bxc-error');
                }
                installation_data[id] = value;
            });
            if (error) {
                error = 'Username, password and the database details are required.';
                let password = installation_data.password;
                if (password) {
                    if (password.length < 8) {
                        error = 'Minimum password length is 8 characters.';
                    } else if (password != installation_data['password-check']) {
                        error = 'The passwords do not match.';
                    }
                }
                showError(error, this);
                return;
            }
            if (url.includes('?')) url = url.substr(0, url.indexOf('?'));
            installation_data['url'] = url + '/';
            ajax('installation', { installation_data: JSON.stringify(installation_data) }, (response) => {
                if (response === true) {
                    location.reload();
                } else {
                    showError(response, this);
                }
            });
        });

        main.on('click', '.bxc-nav > div', function () {
            nav(_(this));
        });

        main.on('click', '#bxc-submit-login', function () {
            if (loading(this)) return;
            ajax('login', { username: main.find('#username input').val().trim(), password: main.find('#password input').val().trim() }, (response) => {
                if (response) {
                    BOXCoin.cookie('BXC_LOGIN', response, 365, 'set');
                    location.reload();
                } else {
                    main.find('.bxc-info').html('Invalid username or password.');
                    loading(this, false);
                }
            });
        });

        main.on('click', '#bxc-logout', function () {
            BOXCoin.cookie('BXC_LOGIN', false, false, 'delete');
            location.reload();
        });

        main.on('click', '#bxc-card', function () {
            _(this).html('');
        });

        main.on('input', '.bxc-search-input', function () {
            BOXCoin.search(this, (search, icon) => {
                if (active_area_id == 'transactions') {
                    BXCTransactions.query(icon, search);
                }
            });
        });

        main.on('click', '#bxc-table-balances tr', function () {
            let cryptocurrency = _(this).attr('data-cryptocurrency');
            window.open(BXCAdmin.explorer(cryptocurrency, BXC_ADDRESS[cryptocurrency]));
        });

        main.on('click', '.bxc-select', function () {
            let ul = _(this).find('ul');
            let active = ul.hasClass('bxc-active');
            activate(ul, !active);
            if (!active) setTimeout(() => { active_element = ul.e[0] }, 300); 

        });

        main.on('click', '.bxc-select li', function () {
            let select = _(this.closest('.bxc-select'));
            let value = _(this).attr('data-value');
            var item = select.find(`[data-value="${value}"]`);
            activate(select.find('li'), false);
            select.find('p').attr('data-value', value).html(item.html());
            activate(item, true);
            active_element = false;
        });

        window.onscroll = function () {
            if (window.scrollTop + window.innerHeight == _.documentHeight() && pagination_count) {
                if (active_area_id == 'transactions') {
                    if (!active_area.find(' > .bxc-loading-global').length) {
                        BXCTransactions.print(() => {
                            scrollBottom();
                            active_area.find(' > .bxc-loading-global').remove();
                        }, active_area.find('.bxc-search-input').val());
                        active_area.append('<div class="bxc-loading-global bxc-loading"></div>');
                    }
                }
            }
        };

        window.onpopstate = function () {
            openURL();
        }

        document.addEventListener('click', function (e) {
            if (active_element && !active_element.contains(e.target)) {
                activate(active_element, false);
                active_element = false;
            }
        });
    });

}());