{{--
    Checkout. Website Phase 2, slice 2.4.

    ⚠⚠ THERE IS NO DELIVERY ZONE, BAND OR CHARGE FIELD ON THIS PAGE, and the next
    person to touch this template is who would wire one back in. The basket holds
    the district; the shop prices it. LaLaDia asked a customer for both their
    district AND their "delivery zone", compared them nowhere, and charged
    Bagerhat the Dhaka rate for months.

    ⚠⚠ AND THERE IS NO POSTCODE FIELD. It is derived on the server from the
    district and thana (slice 2.2). A postcode printed on a page is one a form
    can post back, and then the shop is trusting a stranger's arithmetic about
    its own geography.

    ⚠ AN ORDER CAN BE PLACED WITH NO SCRIPT AT ALL. The district lives on the
    basket, so the thana list is already narrowed by the time this page renders,
    and every required field is a plain input. There is exactly one script, at
    the foot of the form, and all it does is fill the OPTIONAL area list from the
    thana just chosen - so a customer whose browser never runs it loses a sharper
    postcode and nothing else. Script improves this page; it never enables it.

    ⚠ Every figure was resolved a moment ago by CartPresenter. The template shows
    what it was handed and never multiplies.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    <section class="block">
        <div class="wrap">
            <h1>Checkout</h1>

            @if ($cart['lines'] === [])
                <p class="muted">Your basket is empty, so there is nothing to check out.</p>
                <p><a href="/">Back to the shop</a></p>
            @else
                @if ($errors->any())
                    <p class="error">{{ $errors->first() }}</p>
                @endif

                {{-- ⚠ What is being bought, stated again on the page where
                     somebody commits. A checkout that hides the basket asks for
                     a decision without showing what it is about. --}}
                <table class="basket">
                    <thead>
                        <tr><th>Item</th><th>Quantity</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($cart['lines'] as $line)
                        <tr @if ($line['problem']) class="problem" @endif>
                            <td>
                                {{ $line['title'] }}
                                @if ($line['parts'] !== [])<p class="muted">@foreach ($line['parts'] as $part){{ $part['quantity'] }} &times; {{ $part['title'] }}@if (! $loop->last), @endif @endforeach</p>@endif
                                @if ($line['problem'])<p class="muted">{{ $line['problem'] }}</p>@endif
                            </td>
                            <td>{{ $line['quantity'] }}</td>
                            <td>@if ($line['line_total'])BDT {{ $line['line_total'] }}@else<span class="muted">&mdash;</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        <tr><th colspan="2">Subtotal</th><th>BDT {{ $cart['subtotal'] }}</th></tr>
                        @if ($cart['coupon'] && $cart['coupon']['discount'])
                            <tr><th colspan="2">Code {{ $cart['coupon']['code'] }}</th><th>&minus; BDT {{ $cart['coupon']['discount'] }}</th></tr>
                        @endif
                        @if ($cart['coupon']['gift'] ?? null)
                            <tr><th colspan="2">Free with {{ $cart['coupon']['code'] }}: {{ $cart['coupon']['gift']['quantity'] }} &times; {{ $cart['coupon']['gift']['title'] }}</th><th>BDT 0.00</th></tr>
                        @endif
                        @if ($cart['delivery']['charge'])
                            <tr><th colspan="2">Delivery to {{ $cart['delivery']['district'] }}@if ($cart['coupon']['free_delivery'] ?? false) (free with {{ $cart['coupon']['code'] }})@endif</th><th>BDT {{ $cart['delivery']['charge'] }}</th></tr>
                        @endif
                        @if ($cart['total'])
                            <tr><th colspan="2">Total</th><th>BDT {{ $cart['total'] }}</th></tr>
                        @endif
                    </tfoot>
                </table>

                {{-- ⛔ A code that no longer applies is refused at the button with its
                     reason (slice 2.5b), and said here first so nobody types an
                     address to find out. --}}
                @error('code')<p class="error">{{ $message }}</p>@enderror
                @if ($cart['coupon']['problem'] ?? null)
                    <p class="error">Code {{ $cart['coupon']['code'] }}: {{ $cart['coupon']['problem'] }} Please <a href="/cart">remove it from your basket</a> to order without it.</p>
                @endif

                @if ($cart['problems'] > 0)
                    {{-- ⛔ Says so before the button rather than refusing after
                         it. The server refuses this basket anyway; a customer
                         should not have to fill in an address to find that out. --}}
                    <p class="error">
                        {{ $cart['problems'] === 1 ? 'One item' : $cart['problems'].' items' }} in your basket cannot be sold right now.
                        Please <a href="/cart">go back to your basket</a> and remove {{ $cart['problems'] === 1 ? 'it' : 'them' }}.
                    </p>
                @endif

                @if ($district === null)
                    {{-- The district is chosen on the basket, on purpose: it is
                         what the delivery charge is quoted from, and one page
                         owning that answer is what keeps the pair from
                         disagreeing. --}}
                    <p class="error">Please <a href="/cart">choose the district we are delivering to</a> first.</p>
                @elseif ($cart['delivery']['cannot_quote'])
                    <p class="error">We cannot work out a delivery charge for {{ $district }} yet. Please contact us and we will take your order by phone.</p>
                @else
                    <form method="post" action="/checkout" class="checkout">
                        @csrf

                        <h2>Who is receiving this order</h2>

                        {{-- ⚠ The RECIPIENT, not necessarily the buyer. A gift is
                             a real and common order on these shops, and a rider
                             calls the person at the door. --}}
                        <p>
                            <label for="co-name">Full name</label>
                            <input id="co-name" type="text" name="name" value="{{ old('name') }}" maxlength="150" required>
                            @error('name')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        <p>
                            <label for="co-phone">Mobile number</label>
                            {{-- ⚠ `type=tel` and NO pattern. A browser-side
                                 format rule is how LaLaDia's landing pages
                                 refused "+880 1880-136055" - a real number,
                                 typed normally, rejected before any request was
                                 made. The server normalises instead. --}}
                            <input id="co-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="32" required>
                            @error('phone')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        <p>
                            <label for="co-email">Email <span class="muted">(optional)</span></label>
                            <input id="co-email" type="email" name="email" value="{{ old('email') }}" maxlength="190">
                            @error('email')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        <h2>Where it is going</h2>

                        <p>
                            <label>District</label>
                            {{-- ⚠ SHOWN, NOT ASKED. It was chosen on the basket
                                 and the delivery charge above was quoted from it.
                                 There is deliberately no input here, not even a
                                 hidden one: a hidden field is a field. --}}
                            <span class="stated">{{ $district }}</span>
                            <a href="/cart" class="muted">Change</a>
                            @error('district')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        <p>
                            <label for="co-thana">Thana / Upazila</label>
                            <select id="co-thana" name="thana" required>
                                <option value="">Choose your thana</option>
                                @foreach ($thanas as $option)
                                    <option value="{{ $option['name'] }}" @selected(old('thana') === $option['name'])>
                                        {{ $option['name'] }}@if ($option['bn_name']) &mdash; {{ $option['bn_name'] }}@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('thana')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        {{-- Area, and it is the one control that needs script.

                             ⚠ Areas belong to a thana, and choosing a thana does
                             not reload this page - so on a first pass the server
                             has no thana to list areas for. The block is rendered
                             HIDDEN and filled by the script below from the geo
                             endpoints slice 2.2 already built.

                             ⭐ Which is why area is OPTIONAL and the postcode is
                             derived from the district and thana alone: a customer
                             with no script loses a sharper postcode and nothing
                             else. They can still buy, which is the rule this
                             whole page follows. ⚠ Hidden rather than always
                             shown, because a select that can never be filled is
                             a dead control, not a courtesy. --}}
                        <p id="co-area-row" {{ $areas === [] ? 'hidden' : '' }}>
                            <label for="co-area">Area <span class="muted">(optional)</span></label>
                            <select id="co-area" name="area">
                                <option value="">Choose your area</option>
                                @foreach ($areas as $option)
                                    <option value="{{ $option['name'] }}" @selected(old('area') === $option['name'])>
                                        {{ $option['name'] }}@if ($option['bn_name']) &mdash; {{ $option['bn_name'] }}@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('area')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        <p>
                            <label for="co-address">Street address</label>
                            <textarea id="co-address" name="address_line" rows="3" maxlength="500" required>{{ old('address_line') }}</textarea>
                            @error('address_line')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        <p>
                            <label for="co-landmark">Landmark for the rider <span class="muted">(optional)</span></label>
                            <input id="co-landmark" type="text" name="landmark" value="{{ old('landmark') }}" maxlength="255" placeholder="Second gate, ask for the shop">
                            @error('landmark')<span class="error">{{ $message }}</span>@enderror
                        </p>

                        <h2>How you will pay</h2>

                        @if ($online_payment)
                            {{-- ⭐ SSLCommerz slice P2c: a real choice, because the
                                 shop can record either right now. Cash on delivery
                                 stays the default. --}}
                            <p>
                                <label class="choice">
                                    <input type="radio" name="payment_method" value="cod" @checked(old('payment_method', 'cod') === 'cod')>
                                    Cash on delivery &mdash; pay the rider when your parcel arrives.
                                </label>
                            </p>
                            <p>
                                <label class="choice">
                                    <input type="radio" name="payment_method" value="sslcommerz" @checked(old('payment_method') === 'sslcommerz')>
                                    Pay now online &mdash; card, bKash, Nagad or internet banking, through SSLCommerz.
                                </label>
                            </p>
                        @else
                            {{-- ⚠ One method, stated rather than offered as a choice
                                 of one. A disabled radio button is a promise the
                                 shop cannot keep today. --}}
                            <p class="stated">Cash on delivery &mdash; pay the rider when your parcel arrives.</p>
                            <input type="hidden" name="payment_method" value="cod">
                        @endif
                        {{-- ⭐ D24: said again on the page where somebody commits. --}}
                        <p class="stated">{{ $store->deliveryPromise() }}</p>
                        @error('payment_method')<span class="error">{{ $message }}</span>@enderror

                        <p>
                            <button type="submit" @disabled($cart['problems'] > 0)>Place order</button>
                        </p>

                        <p class="muted">
                            @if ($online_payment)
                                Paying online takes you to SSLCommerz to pay. With cash on delivery nothing is charged now.
                            @else
                                Nothing is charged now.
                            @endif
                            We will confirm your order before it is dispatched.
                        </p>
                    </form>

                    {{-- ⚠ PROGRESSIVE, and it is the only script on this page.
                         It fills the area list from the thana the customer just
                         chose. If it never runs, the order still goes through
                         with no area - which is why nothing here is required and
                         no total, price or charge is computed in the browser.

                         ⛔ The endpoint deliberately returns NO postcode, so
                         there is nothing here for a form to post back. --}}
                    <script>
                        (function () {
                            var thana = document.getElementById('co-thana');
                            var row = document.getElementById('co-area-row');
                            var area = document.getElementById('co-area');

                            if (!thana || !row || !area) {
                                return;
                            }

                            thana.addEventListener('change', function () {
                                row.hidden = true;
                                area.innerHTML = '<option value="">Choose your area</option>';

                                if (!thana.value) {
                                    return;
                                }

                                fetch('/geo/areas?district=' + encodeURIComponent(@json($district)) + '&thana=' + encodeURIComponent(thana.value), {
                                    headers: { 'Accept': 'application/json' },
                                })
                                    .then(function (response) { return response.ok ? response.json() : { data: [] }; })
                                    .then(function (payload) {
                                        var list = (payload && payload.data) || [];

                                        list.forEach(function (item) {
                                            var option = document.createElement('option');
                                            option.value = item.name;
                                            option.textContent = item.bn_name ? item.name + ' — ' + item.bn_name : item.name;
                                            area.appendChild(option);
                                        });

                                        row.hidden = list.length === 0;
                                    })
                                    // ⚠ A failed lookup leaves the row hidden and
                                    // the order placeable. An optional field that
                                    // breaks a checkout is worse than no field.
                                    .catch(function () { row.hidden = true; });
                            });
                        })();
                    </script>
                @endif

                <p><a href="/cart">Back to your basket</a></p>
            @endif
        </div>
    </section>
@endsection
