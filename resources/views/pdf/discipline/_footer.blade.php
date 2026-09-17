<div class="alt">
  <table><tr>
    <td>{{ $institution['name'] ?? '' }}{{ !empty($institution['address']) ? ' · '.$institution['address'] : '' }}{{ !empty($institution['phone']) ? ' · '.$institution['phone'] : '' }}</td>
    <td class="r">{{ $institution['generated_at'] ?? now()->format('d.m.Y H:i') }} · <span class="sayfa"></span></td>
  </tr></table>
</div>
