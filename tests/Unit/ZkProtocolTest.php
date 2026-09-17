<?php

namespace Tests\Unit;

use App\Services\Devices\Zk\Exceptions\ZkProtocolException;
use App\Services\Devices\Zk\ZkCodec;
use App\Services\Devices\Zk\ZkPacket;
use App\Services\Devices\Zk\ZkProtocol;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeZkDevice;

/**
 * Paket ve çözücü katmanları (saf mantık, ağ yok).
 * Değerler pyzk / node-zklib / adrobinoga zk-protocol kaynaklarından doğrulandı.
 */
class ZkProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set('Europe/Istanbul');
    }

    // ======================================================== sağlama (checksum)

    public function test_checksum_matches_reference_implementation(): void
    {
        // pyzk/php_zklib davranışı: komut 2000, sağlama alanı 0, oturum 0x8DF3, yanıt 10, veri 0x09
        $buffer = pack('vvvv', 2000, 0, 0x8DF3, 10)."\x09";

        $this->assertSame(0x6A28, ZkProtocol::checksum($buffer));

        // Cihazın kendi ürettiği değer bir fazladır (0x6A29); bu yüzden gelen paketlerde
        // sağlama DOĞRULANMAZ. Bu testin amacı farkın bilinçli olduğunu belgelemektir.
        $this->assertSame(0x6A29, 0xFFFF - (0x07D0 + 0x8DF3 + 0x000A + 0x09));
    }

    public function test_checksum_handles_odd_length_and_carry(): void
    {
        $this->assertSame(0xFFFE, ZkProtocol::checksum(''));
        $this->assertSame(ZkProtocol::checksum("\x01"), ZkProtocol::checksum("\x01\x00"));
        // Taşma katlanır: iki büyük sözcük 65535'i aşar
        $this->assertGreaterThan(0, ZkProtocol::checksum(pack('vv', 0xFFFF, 0xFFFF)));
    }

    // ======================================================== çerçeve

    public function test_command_frame_has_eight_byte_header_and_advances_reply_id(): void
    {
        // pyzk: reply_id 65534'ten başlar, ilk komutta 0'a düşer → CMD_CONNECT reply_id = 0 gider
        [$frame, $next] = ZkProtocol::buildCommandFrame(ZkProtocol::CMD_CONNECT, '', 0, ZkProtocol::USHRT_MAX - 1);

        $this->assertSame(0, $next);
        $this->assertSame(8, strlen($frame));

        $head = unpack('vcommand/vchecksum/vsession/vreply', $frame);
        $this->assertSame(ZkProtocol::CMD_CONNECT, $head['command']);
        $this->assertSame(0, $head['session']);
        $this->assertSame(0, $head['reply']);

        [, $next2] = ZkProtocol::buildCommandFrame(ZkProtocol::CMD_EXIT, '', 5, 0);
        $this->assertSame(1, $next2);
    }

    public function test_tcp_wrapper_uses_zk_magic_and_declares_length(): void
    {
        $wrapped = ZkProtocol::wrapTcp(str_repeat("\x00", 12));

        $this->assertSame('5050827d', substr(bin2hex($wrapped), 0, 8));
        $this->assertSame(12, ZkProtocol::readTcpTop(substr($wrapped, 0, 8)));
    }

    public function test_invalid_tcp_magic_is_rejected_with_turkish_message(): void
    {
        $this->expectException(ZkProtocolException::class);
        $this->expectExceptionMessage('tanınmayan');

        ZkProtocol::readTcpTop(pack('vvV', 1, 2, 16));
    }

    public function test_packet_parse_splits_header_and_data(): void
    {
        $packet = ZkPacket::parse(pack('vvvv', ZkProtocol::CMD_ACK_OK, 0x1234, 0x5678, 3).'merhaba');

        $this->assertSame(ZkProtocol::CMD_ACK_OK, $packet->command);
        $this->assertSame(0x5678, $packet->sessionId);
        $this->assertSame(3, $packet->replyId);
        $this->assertSame('merhaba', $packet->data);
        $this->assertTrue($packet->isSuccess());
        $this->assertSame('ACK_OK', $packet->commandName());
    }

    public function test_prepare_data_and_data_count_as_success(): void
    {
        $this->assertTrue(ZkProtocol::isSuccess(ZkProtocol::CMD_ACK_OK));
        $this->assertTrue(ZkProtocol::isSuccess(ZkProtocol::CMD_PREPARE_DATA));
        $this->assertTrue(ZkProtocol::isSuccess(ZkProtocol::CMD_DATA));
        $this->assertFalse(ZkProtocol::isSuccess(ZkProtocol::CMD_ACK_UNAUTH));
        $this->assertFalse(ZkProtocol::isSuccess(ZkProtocol::CMD_ACK_ERROR));
    }

    // ======================================================== yükler

    public function test_buffer_request_payload_matches_documented_bytes(): void
    {
        // adrobinoga/zk-protocol + node-zklib: yoklama isteği 01 0d 00 00 00 00 00 00 00 00 00
        $this->assertSame('010d000000000000000000', bin2hex(ZkProtocol::bufferRequest(ZkProtocol::CMD_ATTLOG_RRQ, 0)));
        // kullanıcı isteği 01 09 00 05 00 00 00 00 00 00 00
        $this->assertSame('0109000500000000000000', bin2hex(ZkProtocol::bufferRequest(ZkProtocol::CMD_USERTEMP_RRQ, ZkProtocol::FCT_USER)));
        $this->assertSame(11, strlen(ZkProtocol::bufferRequest(ZkProtocol::CMD_ATTLOG_RRQ)));
        $this->assertSame(8, strlen(ZkProtocol::chunkRequest(0, 1024)));
    }

    public function test_comm_key_matches_pyzk_algorithm(): void
    {
        $key = ZkProtocol::commKey(0, 0);
        $this->assertSame(4, strlen($key));
        // key=0, session=0 → c = 00 00 00 00; XOR 'ZKSO' → 5A 4B 53 4F; sonuç: c2^B, c3^B, B, c1^B
        $this->assertSame(chr(0x53 ^ 50).chr(0x4F ^ 50).chr(50).chr(0x4B ^ 50), $key);

        // Oturum numarası değişince anahtar da değişir (tekrar saldırısına karşı).
        // NOT: algoritmanın kendisi en düşük baytı (c0) atar; bu yüzden oturum numarasının
        // yalnız son baytı değişirse anahtar aynı kalır — pyzk'ta da böyledir.
        $this->assertNotSame(ZkProtocol::commKey(1234, 100), ZkProtocol::commKey(1234, 356));
        $this->assertSame(ZkProtocol::commKey(1234, 100), ZkProtocol::commKey(1234, 101));
        $this->assertNotSame(ZkProtocol::commKey(1234, 100), ZkProtocol::commKey(4321, 100));
    }

    // ======================================================== zaman

    public function test_time_encoding_round_trips(): void
    {
        foreach (['2026-09-17 14:35:09', '2000-01-01 00:00:00', '2026-12-31 23:59:59'] as $value) {
            $time = CarbonImmutable::parse($value);
            $this->assertSame($value, ZkProtocol::decodeTime(ZkProtocol::encodeTime($time))->format('Y-m-d H:i:s'));
        }
    }

    public function test_encode_time_matches_sdk_formula(): void
    {
        $time = CarbonImmutable::parse('2026-09-17 14:35:09');
        $expected = (((26 * 12 * 31) + (8 * 31) + 16) * 86400) + ((14 * 60 + 35) * 60) + 9;

        $this->assertSame($expected, ZkProtocol::encodeTime($time));
        $this->assertSame('2026-09-17 14:35:09', ZkProtocol::decodeTimeBytes(pack('V', $expected))->format('Y-m-d H:i:s'));
    }

    public function test_parameter_response_is_parsed(): void
    {
        $this->assertSame('YT33-0001', ZkProtocol::parseParameter("~SerialNumber=YT33-0001\0çöp"));
        $this->assertSame('10', ZkProtocol::parseParameter("~ZKFPVersion=10\0"));
    }

    // ======================================================== çözücü (codec)

    public function test_decodes_new_forty_byte_attendance_records(): void
    {
        $device = new FakeZkDevice(['records' => 4, 'record_size' => 40, 'base_time' => '2026-09-15 08:00:00']);
        $records = ZkCodec::attendance($device->attendanceData(), 4);

        $this->assertCount(4, $records);
        $this->assertSame('1001', $records[0]->userId);
        $this->assertSame('2026-09-15 08:00:00', $records[0]->timestamp->format('Y-m-d H:i:s'));
        $this->assertSame(0, $records[0]->status);          // punch: giriş
        $this->assertSame(1, $records[0]->verify);          // doğrulama: parmak izi
        $this->assertSame(1, $records[1]->status);          // punch: çıkış
        $this->assertSame('2026-09-15 08:07:00', $records[1]->timestamp->format('Y-m-d H:i:s'));
    }

    public function test_decodes_legacy_sixteen_byte_records(): void
    {
        $device = new FakeZkDevice(['records' => 3, 'record_size' => 16]);
        $records = ZkCodec::attendance($device->attendanceData(), 3);

        $this->assertCount(3, $records);
        $this->assertSame('1001', $records[0]->userId);
        $this->assertSame('1002', $records[1]->userId);
    }

    public function test_decodes_oldest_eight_byte_records_using_user_list(): void
    {
        $device = new FakeZkDevice(['records' => 2, 'record_size' => 8]);
        $records = ZkCodec::attendance($device->attendanceData(), 2, [1 => '1001', 2 => '1002']);

        $this->assertCount(2, $records);
        $this->assertSame('1001', $records[0]->userId);
        $this->assertSame('1002', $records[1]->userId);

        // Eşleme listesi yoksa cihaz iç numarası kullanılır (kayıt KAYBOLMAZ)
        $fallback = ZkCodec::attendance($device->attendanceData(), 2);
        $this->assertSame('1', $fallback[0]->userId);
    }

    public function test_decodes_new_and_legacy_user_records(): void
    {
        $new = ZkCodec::users((new FakeZkDevice(['user_size' => 72]))->userData(), 3);
        $this->assertCount(3, $new);
        $this->assertSame('1001', $new[0]->userId);
        $this->assertSame('Ayse Yilmaz', $new[0]->name);
        $this->assertSame('1234567', $new[0]->card);
        $this->assertSame('Kullanıcı', $new[0]->privilegeLabel());
        $this->assertSame('Yönetici', $new[2]->privilegeLabel());

        $old = ZkCodec::users((new FakeZkDevice(['user_size' => 28]))->userData(), 3);
        $this->assertCount(3, $old);
        $this->assertSame('1001', $old[0]->userId);
        $this->assertSame('Ayse Yil', $old[0]->name);   // eski biçimde ad 8 karakter
    }

    public function test_sizes_payload_offsets(): void
    {
        $sizes = ZkCodec::sizes((new FakeZkDevice(['records' => 42]))->respond(ZkProtocol::CMD_GET_FREE_SIZES, '')[0][1]);

        $this->assertSame(3, $sizes['users']);
        $this->assertSame(42, $sizes['records']);
        $this->assertSame(3000, $sizes['users_cap']);
        $this->assertSame(100000, $sizes['records_cap']);
    }

    public function test_empty_or_broken_data_yields_no_records(): void
    {
        $this->assertSame([], ZkCodec::attendance('', 5));
        $this->assertSame([], ZkCodec::attendance(pack('V', 0), 0));
        $this->assertSame([], ZkCodec::users('abc', 3));
    }

    public function test_device_names_in_other_code_pages_are_converted(): void
    {
        // Cihaz adları UTF-8 olmayabilir; Windows-1254 (Türkçe) varsayılır.
        $this->assertSame('Şule', ZkCodec::text(mb_convert_encoding('Şule', 'Windows-1254', 'UTF-8')."\0"));
        $this->assertSame('Ali', ZkCodec::text("Ali\0\x01\x02"));
    }
}
