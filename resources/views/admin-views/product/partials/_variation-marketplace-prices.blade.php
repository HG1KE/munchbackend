@php
    $channelPrices = \App\Support\ProductVariationPricing::channelPricesFromOption($value ?? []);
@endphp
<div class="col-md-2 col-4">
    <label for="">Uber Price</label>
    <input class="form-control" type="number" min="0" step="0.01"
           name="options[{{ $key }}][values][{{ $key_value }}][channelPrices][uber]"
           value="{{ $channelPrices['uber'] ?? '' }}" placeholder="—">
</div>
<div class="col-md-2 col-4">
    <label for="">Glovo Price</label>
    <input class="form-control" type="number" min="0" step="0.01"
           name="options[{{ $key }}][values][{{ $key_value }}][channelPrices][glovo]"
           value="{{ $channelPrices['glovo'] ?? '' }}" placeholder="—">
</div>
<div class="col-md-2 col-4">
    <label for="">Bolt Food Price</label>
    <input class="form-control" type="number" min="0" step="0.01"
           name="options[{{ $key }}][values][{{ $key_value }}][channelPrices][bolt_food]"
           value="{{ $channelPrices['bolt_food'] ?? '' }}" placeholder="—">
</div>
