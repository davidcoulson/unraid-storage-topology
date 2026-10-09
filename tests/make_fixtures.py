#!/usr/bin/env python3
"""Writes the synthetic test fixtures under tests/fixtures/ (what scripts/collect.sh would leave in its cache).

All serial numbers, SAS addresses and names are made up. Run from anywhere: python3 tests/make_fixtures.py
Layouts:
  it-mode-sas3224        LSI SAS9305-24i in IT mode, 6 SATA SSDs on host PHYs, x4 to an LSI SAS2X28 expander (6G)
                         with 12 SES bays of SAS HDDs and one SATA SSD on an expander PHY that no bay points to.
  usb-short-ses          No SAS at all: two USB drive boxes whose SES device only answers the short status page.
  hba-wide-8             9400-16i-like HBA, PHYs 0-7 as one wide port to a 12G expander (two connectors).
  hba-wide-4-c1-unused   The same, PHYs 0-3 only; connector C1 unused.
  hba-partial-c1         PHYs 0-5 linked to the expander: C1 has 2 of 4 lanes.
  hba-dual-expander      C0 to the primary and C1 to the secondary expander of one dual-expander backplane.
  storcli-direct         MegaRAID (storcli) with a drive in virtual enclosure 252 and one with no enclosure.
  emc-ktn-stl3           EMC KTN-STL3 (Viper DAE): 5 subenclosures, no element descriptor page, PSU B without AC.
"""
import json
import os
import shutil

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures')
DONE = '1790000000\n'


def write(d, name, text):
    os.makedirs(d, exist_ok=True)
    with open(os.path.join(d, name), 'w') as f:
        f.write(text)


def lsscsi_line(hctl, typ, vendor, product, rev, dev, sg):
    return f"{'[' + hctl + ']':<13}{typ:<8}{vendor:<8} {product:<16} {rev:<4}  {dev:<10} {sg}\n"


def ini(sections):
    out = ''
    for name, d in sections:
        out += f'["{name}"]\n' + ''.join(f'{k}="{v}"\n' for k, v in d.items())
    return out


def lsblk(devs):
    return json.dumps({'blockdevices': [{'name': n, 'serial': s, 'wwn': w, 'tran': t, 'model': m, 'size': z}
                                        for n, s, w, t, m, z in devs]}, indent=1) + '\n'


def addr(a):
    return f'0x{a:016x}'


def element(etype, meaning, n, status='OK', desc='', **sd):
    """One page-2 element as sg_ses --json --join writes it (n = -1: the overall element)."""
    st = {'i': {'OK': 1, 'Critical': 2, 'Noncritical': 3, 'Unrecoverable': 4, 'Not installed': 5, 'Unknown': 6,
                'Unsupported': 0}[status], 'meaning': status}
    e = {'element_type': {'i': etype, 'meaning': meaning}, 'descriptor': desc, 'element_number': n,
         'overall': 1 if n < 0 else 0, 'individual': n >= 0,
         'status_descriptor': {'prdfail': 0, 'disabled': 0, 'swap': 0, 'status': st}}
    aes = sd.pop('aes', None)
    e['status_descriptor'].update(sd)
    if aes is not None:
        e['additional_element_status_descriptor'] = aes
    return e


def slot_aes(slot, sas, attached):
    return {'descriptor_type': 0, 'number_of_phy_descriptors': 1, 'not_all_phys': 0, 'device_slot_number': slot,
            'phy_descriptor_list': [{'device_type': {'i': 1, 'meaning': 'end device'}, 'ssp_target_port': 1,
                                     'attached_sas_address': attached, 'sas_address': sas, 'phy_index': 0}]}


def ses_json(elements):
    return json.dumps({'json_format_version': {'major': 1, 'minor': 0},
                       'join_of_diagnostic_pages': {'element_list': elements},
                       'exit_status': {'i': 0, 'meaning': 'no errors'}}, indent=1) + '\n'


def fan(n, status='OK', rpm=3000, fail=0):
    return element(3, 'Cooling', n, status, actual_fan_speed=rpm // 10, calculated_fan_speed=rpm, fail=fail,
                   actual_fan_code={'i': 1 if rpm else 0, 'meaning': 'at lowest speed' if rpm else 'stopped'})


def temp(n, c, status='OK'):
    return element(4, 'Temperature sensor', n, status, temperature={'i': c + 20, 'meaning': f'{c} C'},
                   ot_failure=0, ot_warning=0, ut_failure=0, ut_warning=0, fail=0)


def psu(n, status='OK', desc='', **flags):
    f = {k: 0 for k in ['dc_over_voltage', 'dc_under_voltage', 'dc_over_current', 'fail', 'off', 'overtmp_fail',
                        'temp_warn', 'ac_fail', 'dc_fail']}
    f.update(flags)
    return element(2, 'Power supply', n, status, desc, **f)


def encl_el(n, status='OK', desc='', fail_ind=0, warn_ind=0):
    return element(14, 'Enclosure', n, status, desc, failure_indication=fail_ind, warning_indication=warn_ind)


class Kernel:
    """Builds the kernel SAS view files: sas_phys.txt, expanders.txt, expander_phys.txt, end_devices.txt."""

    def __init__(self, host, nphys, hba_sas, max_hw='12.0 Gbit'):
        self.host, self.hba = host, hba_sas
        self.hphys = {n: ['Unknown', '12.0 Gbit', '', ''] for n in range(nphys)}   # neg, max, port, att
        self.max_hw = max_hw
        self.ports = 0
        self.exp = []        # name, sas, vendor, product, rev, parent
        self.ephys = []      # lines
        self.ends = []       # lines
        self.target = 0

    def port(self):
        p = f'port-{self.host}:{self.ports}'
        self.ports += 1
        return p

    def end(self, port, phys, sas, proto, typ, vendor, model, block):
        name = f'end_device-{port[5:]}'
        hctl = f'{self.host}:0:{self.target}:0'
        self.target += 1
        self.ends.append(f'{name}|{addr(sas)}|{proto}|{port}|{",".join(phys)}|{hctl}|{typ}|{vendor}|{model}|{block}')
        return name, hctl

    def direct(self, phy, sas, proto, vendor, model, block, rate='6.0 Gbit'):
        port = self.port()
        name, hctl = self.end(port, [f'phy-{self.host}:{phy}'], sas, proto, 0, vendor, model, block)
        self.hphys[phy] = [rate, '12.0 Gbit', port, f'{name}={addr(sas)}']
        return hctl

    def expander(self, phys, sas, product, nexp_phys, rate, max_hw, vendor='LSI'):
        port = self.port()
        e = len(self.exp)
        name = f'expander-{self.host}:{e}'
        self.exp.append(f'{name}|{addr(sas)}|{vendor}|{product}|0717|{port}')
        for p in phys:
            self.hphys[p] = [rate, '12.0 Gbit', port, f'{name}={addr(sas)}']
        x = {'name': name, 'n': e, 'sas': sas, 'ports': 0, 'phys': {}, 'max_hw': max_hw}
        for i in range(nexp_phys):
            x['phys'][i] = ['Unknown', max_hw, max_hw, '', '']
        for i, _ in enumerate(phys):              # upstream PHYs: linked, in no port
            x['phys'][i] = [rate, max_hw, max_hw, '', '']
        self.exp_obj = getattr(self, 'exp_obj', []) + [x]
        return x

    def behind(self, x, ephy, sas, proto, typ, vendor, model, block, rate='6.0 Gbit'):
        port = f'port-{self.host}:{x["n"]}:{x["ports"]}'
        x['ports'] += 1
        name, hctl = self.end(port, [f'phy-{self.host}:{x["n"]}:{ephy}'], sas, proto, typ, vendor, model, block)
        x['phys'][ephy] = [rate, x['max_hw'], x['max_hw'], port, f'{name}={addr(sas)}']
        return hctl

    def files(self, d):
        hp = ''.join(f'phy-{self.host}:{n}|{v[0]}|{v[1]}|{addr(self.hba)}|{v[2]}|{v[3]}|{self.max_hw}\n'
                     for n, v in sorted(self.hphys.items()))
        write(d, 'sas_phys.txt', hp)
        write(d, 'expanders.txt', ''.join(l + '\n' for l in self.exp))
        ep = ''
        for x in getattr(self, 'exp_obj', []):
            for n, v in sorted(x['phys'].items()):
                ep += f'phy-{self.host}:{x["n"]}:{n}|{v[0]}|{v[1]}|{v[2]}|{addr(x["sas"])}|{v[3]}|{v[4]}\n'
        write(d, 'expander_phys.txt', ep)
        write(d, 'end_devices.txt', ''.join(l + '\n' for l in self.ends))


def common(d, timings='', storcli=''):
    write(d, 'done', DONE)
    write(d, 'timings', timings)
    write(d, 'storcli.path', storcli + '\n')
    write(d, 'sg_ses.version', 'sg_ses version: 2.88 20260526\n')


def it_mode():
    d = os.path.join(ROOT, 'it-mode-sas3224')
    hba = 0x500605b012345600
    k = Kernel(0, 24, hba)
    ssd_model, ssd_lsblk = 'SAMSUNG MZ7LM1T9', 'SAMSUNG MZ7LM1T9HMJP-00005'
    lss, blk, disks = '', [], []
    sg = 0
    # Six SATA SSDs straight on host PHYs 0-3 and 8-9 (breakout cables), sda-sdf.
    for i, phy in enumerate([0, 1, 2, 3, 8, 9]):
        dev = 'sd' + 'abcdef'[i]
        hctl = k.direct(phy, 0x4433221100000000 + phy, 'sata', 'ATA', ssd_model, dev)
        lss += lsscsi_line(hctl, 'disk', 'ATA', ssd_model, '204Q', f'/dev/{dev}', f'/dev/sg{sg}'); sg += 1
        blk.append((dev, f'S2TVNX0TEST{i + 1:04d}', f'0x5002538c4000{i + 1:04x}', 'sas', ssd_lsblk, '1.7T'))
        disks.append(('cache' if i == 0 else f'cache{i + 1}', {'name': 'cache' if i == 0 else f'cache{i + 1}', 'device': dev,
                      'type': 'Cache', 'status': 'DISK_OK', 'temp': str(30 + i), 'spundown': '0', 'numErrors': '0'}))
    # x4 to an LSI SAS2X28 expander (6G) on PHYs 4-7.
    exp_sas = 0x500605b0000a1b3f
    x = k.expander([4, 5, 6, 7], exp_sas, 'SAS2X28', 29, '6.0 Gbit', '6.0 Gbit')
    hdd_model = 'HUH721010AL5200'
    bays = ''
    for i in range(12):
        dev = 'sd' + 'hijklmnopqrs'[i]
        sas = 0x5000cca250000000 + 4 * i + 1
        hctl = k.behind(x, 8 + i, sas, 'ssp', 0, 'HGST', hdd_model, dev)
        lss += lsscsi_line(hctl, 'disk', 'HGST', hdd_model, 'LS21', f'/dev/{dev}', f'/dev/sg{sg}'); sg += 1
        blk.append((dev, f'7JTEST{i + 1:02d}', f'0x5000cca25000{4 * i:04x}', 'sas', hdd_model, '9.1T'))
        name = 'parity' if i == 0 else f'disk{i}'
        disks.append((name, {'name': name, 'device': dev, 'type': 'Parity' if i == 0 else 'Data', 'status': 'DISK_OK',
                             'temp': str(35 + i % 4), 'spundown': '0', 'numErrors': '0'}))
    # The SES processor on a virtual PHY (sg18), and the seventh SSD on expander PHY 20, in no bay (sdg, sg19).
    ses_hctl = k.behind(x, 28, exp_sas - 2, 'ssp', 13, 'LSI CORP', 'SAS2X28', '')
    lss += lsscsi_line(ses_hctl, 'enclosu', 'LSI CORP', 'SAS2X28', '0717', '-', '/dev/sg18')
    hctl = k.behind(x, 20, exp_sas - 0x2b, 'sata', 0, 'ATA', ssd_model, 'sdg')
    lss += lsscsi_line(hctl, 'disk', 'ATA', ssd_model, '204Q', '/dev/sdg', '/dev/sg19')
    blk.append(('sdg', 'S2TVNX0TEST0007', '0x5002538c40000007', 'sas', ssd_lsblk, '1.7T'))
    disks.append(('cache7', {'name': 'cache7', 'device': 'sdg', 'type': 'Cache', 'status': 'DISK_OK', 'temp': '33',
                             'spundown': '0', 'numErrors': '0'}))
    enc_id = '0x500605b0000a1b3e'
    for i in range(12):
        bays += f'{ses_hctl}|{enc_id}|Slot{i:02d}|{i}|OK|0|0|sd{"hijklmnopqrs"[i]}\n'
    # SES: 12 bays, no I/O module elements, no serial; PSUs not installed; fans 1-3 report fail at 0 rpm,
    # fan 4 is not installed and fan 5 is unknown (both with the fail bit, which must be ignored).
    els = [element(23, 'Array device slot', -1, 'Unsupported')]
    for i in range(12):
        els.append(element(23, 'Array device slot', i, 'OK', fault_sensed=0, fault_reqstd=0, ident=0,
                           aes=slot_aes(i, 0x5000cca250000000 + 4 * i + 1, exp_sas)))
    els += [element(2, 'Power supply', -1, 'Unsupported'), psu(0, 'Not installed', off=1), psu(1, 'Not installed', off=1)]
    els += [element(3, 'Cooling', -1, 'Unsupported'), fan(0, 'OK', 0, 1), fan(1, 'OK', 0, 1), fan(2, 'OK', 0, 1),
            fan(3, 'Not installed', 0, 1), fan(4, 'Unknown', 0, 1)]
    els += [element(4, 'Temperature sensor', -1, 'Unsupported'), temp(0, 31), temp(1, 36)]
    els += [element(14, 'Enclosure', -1, 'Unsupported'), encl_el(0)]
    els += [element(18, 'Voltage sensor', -1, 'Unsupported'),
            element(18, 'Voltage sensor', 0, 'OK', voltage={'raw_value': 500, 'value_in_volts': '5.00'}),
            element(18, 'Voltage sensor', 1, 'OK', voltage={'raw_value': 1200, 'value_in_volts': '12.00'})]
    common(d, 'ses_sg18.json|0|41\n')
    write(d, 'ses_sg18.json', ses_json(els))
    write(d, 'lsscsi.txt', lss)
    write(d, 'enclosure_sysfs.txt', bays)
    write(d, 'sas_hosts.txt', 'host0|mpt3sas|"SAS9305-24i"|16.00.12.00\n')
    write(d, 'scsi_hosts.txt', 'host0|mpt3sas\nhost1|ahci\n')
    write(d, 'lsblk.json', lsblk(blk))
    write(d, 'disks.ini', ini(disks))
    write(d, 'devs.ini', '')
    k.files(d)


SHORT_ERR = ("Enclosure only supports Short enclosure status diagnostic page, status=0x{:x}\n"
             "build_type_desc_hdr_arr: couldn't read config page, res=-2\n"
             "Some error occurred, try again with '-v' or '-vv' for more information\n")


def usb():
    d = os.path.join(ROOT, 'usb-short-ses')
    lss = (lsscsi_line('0:0:0:0', 'disk', 'SanDisk', "Cruzer Fit", '1.00', '/dev/sda', '/dev/sg0')
           + lsscsi_line('1:0:0:0', 'disk', 'ATA', 'CT1000MX500SSD1', '046', '/dev/sdb', '/dev/sg1')
           + lsscsi_line('4:0:0:0', 'disk', 'ATA', 'CT1000MX500SSD1', '046', '/dev/sdc', '/dev/sg2')
           + lsscsi_line('2:0:0:0', 'disk', 'WD', 'My Book Duo 25F6', '1011', '/dev/sdf', '/dev/sg5')
           + lsscsi_line('2:0:0:1', 'enclosu', 'WD', 'SES Device', '1011', '-', '/dev/sg6')
           + lsscsi_line('3:0:0:0', 'disk', 'WD', 'My Book 25ED', '1031', '/dev/sdg', '/dev/sg7')
           + lsscsi_line('3:0:0:1', 'enclosu', 'WD', 'SES Device', '1031', '-', '/dev/sg8')
           + '[N:0:1:1]    disk    Samsung SSD 990 PRO 2TB__1                 /dev/nvme0n1  -        \n')
    common(d, 'ses_sg6.json|99|31\nses_sg8.json|99|29\n')
    write(d, 'lsscsi.txt', lss)
    for sg in ('sg6', 'sg8'):
        write(d, f'ses_{sg}.json', '')
        write(d, f'ses_{sg}.json.err', SHORT_ERR.format(0))
        write(d, f'ses_{sg}.short', 'status=0x0\n')
    write(d, 'scsi_hosts.txt', 'host0|usb-storage\nhost1|ahci\nhost2|uas\nhost3|usb-storage\nhost4|ahci\n')
    write(d, 'lsblk.json', lsblk([('sda', 'TESTUSB0001', '', 'usb', 'Cruzer Fit', '28.7G'),
                                  ('sdb', 'TESTSSD0001', '0x500a0751e0000001', 'sata', 'CT1000MX500SSD1', '931.5G'),
                                  ('sdc', 'TESTSSD0002', '0x500a0751e0000002', 'sata', 'CT1000MX500SSD1', '931.5G'),
                                  ('sdf', 'WD-TESTDUO0001', '', 'usb', 'My Book Duo 25F6', '16.4T'),
                                  ('sdg', 'WD-TESTBOOK001', '', 'usb', 'My Book 25ED', '7.3T')]))
    write(d, 'disks.ini', ini([('flash', {'name': 'flash', 'device': 'sda', 'type': 'Flash', 'status': 'DISK_OK'}),
                               ('disk1', {'name': 'disk1', 'device': 'sdb', 'type': 'Data', 'status': 'DISK_OK', 'temp': '31'}),
                               ('disk2', {'name': 'disk2', 'device': 'sdc', 'type': 'Data', 'status': 'DISK_OK', 'temp': '32'}),
                               ('disk3', {'name': 'disk3', 'device': 'sdf', 'type': 'Data', 'status': 'DISK_OK', 'temp': '38'})]))
    write(d, 'devs.ini', ini([('My_Book_25ED_WD-TESTBOOK001', {'device': 'sdg', 'temp': '36'})]))


def hba(name, linked, exps):
    """9400-16i-like HBA (16 PHYs). linked: {phy: expander index}; exps: [(sas, product, max_hw)]."""
    d = os.path.join(ROOT, name)
    k = Kernel(0, 16, 0x500605b0abcd0000)
    lss, blk, disks, bays = '', [], [], ''
    sg, letter = 0, iter('bcdefghijklmnopq')
    for i, (sas, product, max_hw) in enumerate(exps):
        phys = sorted(p for p, e in linked.items() if e == i)
        rate = '12.0 Gbit' if max_hw.startswith('12') else '6.0 Gbit'
        x = k.expander(phys, sas, product, 40, rate, max_hw, vendor='LSI')
        ses_hctl = k.behind(x, 39, sas - 2, 'ssp', 13, 'LSI', product, '')
        ses_sg = f'sg{20 + i}'
        lss += lsscsi_line(ses_hctl, 'enclosu', 'LSI', product, '0717', '-', f'/dev/{ses_sg}')
        els = [element(1, 'Device slot', -1, 'Unsupported')]
        enc_id = f'0x{sas + 0x1000:016x}'
        for b in range(2):
            # Dual-ported SAS drives: port A (+1) on the first expander, port B (+2) on the second, which has
            # no block device of its own here (no multipath in this fixture), so its bays show no disk.
            dsas = 0x5000c50090000000 + 0x100 * b
            els.append(element(1, 'Device slot', b, 'OK', aes=slot_aes(b, dsas + 1 + i, sas)))
            if i:
                continue
            dev = 'sd' + next(letter)
            hctl = k.behind(x, 10 + b, dsas + 1, 'ssp', 0, 'SEAGATE', 'ST16000NM002G', dev, rate)
            lss += lsscsi_line(hctl, 'disk', 'SEAGATE', 'ST16000NM002G', 'E004', f'/dev/{dev}', f'/dev/sg{sg}'); sg += 1
            blk.append((dev, f'ZLTEST{b:02d}', f'0x{dsas:016x}', 'sas', 'ST16000NM002G', '14.6T'))
            disks.append((f'disk{len(disks) + 1}', {'name': f'disk{len(disks) + 1}', 'device': dev, 'type': 'Data', 'status': 'DISK_OK'}))
            bays += f'{ses_hctl}|{enc_id}|Slot{b:02d}|{b}|OK|0|0|{dev}\n'
        for b in range(2, 4):
            els.append(element(1, 'Device slot', b, 'Not installed', aes=slot_aes(b, 0, sas)))
        els += [element(14, 'Enclosure', -1, 'Unsupported'), encl_el(0)]
        write(d, f'ses_{ses_sg}.json', ses_json(els))
    common(d, ''.join(f'ses_sg{20 + i}.json|0|30\n' for i in range(len(exps))))
    write(d, 'lsscsi.txt', lss)
    write(d, 'enclosure_sysfs.txt', bays)
    write(d, 'sas_hosts.txt', 'host0|mpt3sas|"HBA 9400-16i"|24.00.00.00\n')
    write(d, 'scsi_hosts.txt', 'host0|mpt3sas\n')
    write(d, 'lsblk.json', lsblk(blk))
    write(d, 'disks.ini', ini(disks))
    write(d, 'devs.ini', '')
    k.files(d)


def storcli_direct():
    d = os.path.join(ROOT, 'storcli-direct')
    cs = lambda cid=0, status='Success', desc='None': {'CLI Version': '007.2807.0000.0000 Dec 22, 2023',
                                                       'Operating system': 'Linux 6.12.24-Unraid', 'Controller': cid,
                                                       'Status': status, 'Description': desc}
    ctrl = {'Controllers': [{'Command Status': cs(), 'Response Data': {
        'Basics': {'Controller': 0, 'Model': 'MegaRAID 9361-8i', 'Serial Number': 'SKTEST0001', 'SAS Address': '500605b0deadbe00',
                   'PCI Address': '00:02:00:00'},
        'Version': {'Firmware Package Build': '24.21.0-0151', 'Firmware Version': '4.680.00-8527', 'Bios Version': '6.36.00.3_4.19.08.00_0x06180203',
                    'Driver Name': 'megaraid_sas', 'Driver Version': '07.727.03.00-rc1'},
        'Status': {'Controller Status': 'Optimal', 'Current Personality': 'JBOD-Mode'},
        'HwCfg': {'ROC temperature(Degree Celsius)': 61, 'On Board Memory Size': '1024MB'}}}]}
    phy = lambda n, port, typ, rate: {'PhyNo': n, 'SAS_Addr': f'0x{0x4433221100000000 + n:016X}' if port is not None else '0x0',
                                      'Phy_Identifier': 0, 'Link_Speed': rate, 'Device_Type': typ,
                                      'Port': port if port is not None else 255, 'Port_valid': 1 if port is not None else 0,
                                      'Description': '-', 'MaxSpeed': 'No limit ', 'Enbl': 'Y'}
    phys = {'Controllers': [{'Command Status': cs(), 'Response Data': {'PhyInfo': [
        phy(0, None, '', 'Unknown '), phy(1, None, '', 'Unknown '), phy(2, None, '', 'Unknown '), phy(3, 3, 'SATA', '6.0Gb/s '),
        phy(4, None, '', 'Unknown '), phy(5, 5, 'SATA', '6.0Gb/s '), phy(6, None, '', 'Unknown '), phy(7, None, '', 'Unknown ')]}}]}
    encl = {'Controllers': [{'Command Status': cs(), 'Response Data': {'Enclosure /c0/e252 ': {
        'Information': {'Device ID': 252, 'Position': '1', 'Connector Name': 'Unavailable', 'Enclosure Type': 'SGPIO', 'Status': 'OK',
                        'EnclLogicalID': 'N/A'}, 'Properties': [{'EID': 252, 'State': 'OK', 'Slots': 8, 'PD': 1, 'Port#': '-', 'ProdID': 'SGPIO'}]}}}]}

    def drive(path, eidslt, serial, model):
        base = f'Drive {path}'
        return {base: [{'EID:Slt': eidslt, 'DID': 3, 'State': 'JBOD', 'DG': '-', 'Size': '3.638 TB', 'Intf': 'SATA', 'Med': 'HDD',
                        'Model': model, 'Type': 'JBOD'}],
                f'{base} - Detailed Information': {
                    f'{base} State': {'Media Error Count': 0, 'Other Error Count': 0, 'Drive Temperature': ' 33C (91.40 F)',
                                      'Predictive Failure Count': 0, 'S.M.A.R.T alert flagged by drive': 'No'},
                    f'{base} Device attributes': {'SN': serial, 'Manufacturer Id': 'ATA     ', 'Model Number': model,
                                                  'WWN': '5000C500A0000001', 'Firmware Revision': 'SC60', 'Device Speed': '6.0Gb/s',
                                                  'Link Speed': '6.0Gb/s'},
                    f'{base} Policies/Settings': {'Port Information': [{'Port': 0, 'Status': 'Active', 'Linkspeed': '6.0Gb/s', 'SAS address': '0x4433221103000000'}]}}}
    drives = {'Controllers': [{'Command Status': cs(), 'Response Data': drive('/c0/e252/s3', '252:3', 'ZDHTEST1', 'ST4000VN008-2DR166')}]}
    noencl = {'Controllers': [{'Command Status': cs(), 'Response Data': drive('/c0/s5', ' :5', 'ZDHTEST2', 'ST4000VN008-2DR166')}]}
    common(d, 'ctrl.json|0|60\nphys.json|0|25\nencl.json|0|25\ndrives.json|0|90\ndrives_noencl.json|0|40\n', '/usr/sbin/storcli64')
    for n, j in [('ctrl.json', ctrl), ('phys.json', phys), ('encl.json', encl), ('drives.json', drives), ('drives_noencl.json', noencl)]:
        write(d, n, json.dumps(j, indent=1) + '\n')
    write(d, 'megaraid_sas', '')
    write(d, 'lsscsi.txt', lsscsi_line('0:2:3:0', 'disk', 'ATA', 'ST4000VN008-2DR1', 'SC60', '/dev/sdb', '/dev/sg1')
          + lsscsi_line('0:2:5:0', 'disk', 'ATA', 'ST4000VN008-2DR1', 'SC60', '/dev/sdc', '/dev/sg2'))
    write(d, 'scsi_hosts.txt', 'host0|megaraid_sas\n')
    write(d, 'sas_phys.txt', '')
    write(d, 'lsblk.json', lsblk([('sdb', 'ZDHTEST1', '0x5000c500a0000001', 'sas', 'ST4000VN008-2DR166', '3.6T'),
                                  ('sdc', 'ZDHTEST2', '0x5000c500a0000002', 'sas', 'ST4000VN008-2DR166', '3.6T')]))
    write(d, 'disks.ini', ini([('disk1', {'name': 'disk1', 'device': 'sdb', 'type': 'Data', 'status': 'DISK_OK'}),
                               ('disk2', {'name': 'disk2', 'device': 'sdc', 'type': 'Data', 'status': 'DISK_OK'})]))
    write(d, 'devs.ini', '')


def emc():
    """EMC KTN-STL3: one SES device, 5 subenclosures, type descriptor texts but no element descriptors (page 7)."""
    d = os.path.join(ROOT, 'emc-ktn-stl3')
    lcc_a, lcc_b, chassis = 0x500604800a000e3e, 0x500604800a00113e, 0x5006048000000000
    exp_a = lcc_a + 1
    subs = [(0, 'Viper LCC', '0B70', lcc_a), (1, 'Viper LCC', '0B70', lcc_b), (2, 'Viper Encl', '0011', chassis),
            (3, '000B0027', '2150', chassis), (4, '000B0027', '2150', chassis)]
    headers = [  # (type, meaning, sub, count, text)
        (23, 'Array device slot', 0, 15, 'Array Device'), (4, 'Temperature sensor', 0, 1, 'Temp. Sensor A'),
        (14, 'Enclosure', 0, 1, 'LCC A'), (129, 'Vendor specific [0x81]', 0, 25, 'Expander Phy'),
        (24, 'SAS expander', 0, 1, 'Expander A'), (7, 'Enclosure services controller electronics', 0, 1, 'Controller A'),
        (25, 'SAS connector', 0, 10, 'SAS Connector A'), (12, 'Display', 0, 2, 'Display Green'), (12, 'Display', 0, 1, 'Display Blue'),
        (17, 'Language', 0, 1, 'Language'),
        (4, 'Temperature sensor', 1, 1, 'Temp. Sensor B'), (14, 'Enclosure', 1, 1, 'LCC B'),
        (129, 'Vendor specific [0x81]', 1, 8, 'Expander Phy'), (24, 'SAS expander', 1, 1, 'Expander B'),
        (7, 'Enclosure services controller electronics', 1, 1, 'Controller B'), (25, 'SAS connector', 1, 10, 'SAS Connector B'),
        (14, 'Enclosure', 2, 1, 'Enclosure'), (3, 'Cooling', 2, 0, 'Cooling Fan M'), (4, 'Temperature sensor', 2, 1, 'Temp. Sensor M'),
        (25, 'SAS connector', 2, 16, 'SAS Connector M'),
        (3, 'Cooling', 3, 2, 'Cooling Fan A'), (4, 'Temperature sensor', 3, 2, 'Temp. Sensor A'), (2, 'Power supply', 3, 1, 'Power Supply A'),
        (3, 'Cooling', 4, 2, 'Cooling Fan B'), (4, 'Temperature sensor', 4, 2, 'Temp. Sensor B'), (2, 'Power supply', 4, 1, 'Power Supply B')]
    cfg = {'json_format_version': {'major': 1, 'minor': 0},
           'configuration_diagnostic_page': {
               'page_code': {'i': 1, 'meaning': 'Configuration diagnostic page'}, 'number_of_secondary_subenclosures': 4,
               'generation_code': 4,
               'enclosure_descriptor_list': [{'subenclosure_identifier': {'i': i, 'meaning': 'primary' if i == 0 else 'secondary'},
                                              'relative_enclosure_services_process_identifier': 1, 'number_of_enclosure_services_processes': 1,
                                              'number_of_type_descriptor_headers': sum(1 for h in headers if h[2] == i),
                                              'enclosure_logical_identifier': wwn, 'enclosure_vendor_identification': 'EMC     ',
                                              'product_identification': f'{p:<16}', 'product_revision_level': r,
                                              'vendor_specific_enclosure_information': '00'} for i, p, r, wwn in subs],
               'type_descriptor_header_and_text_list': [{'element_type': {'i': t, 'meaning': mn}, 'number_of_possible_elements': c,
                                                         'subenclosure_identifier': s, 'type_descriptor_text_length': len(tx), 'text': tx}
                                                        for t, mn, s, c, tx in headers]},
           'exit_status': {'i': 0, 'meaning': 'no errors'}}
    els = []
    for t, mn, s, c, tx in headers:
        overall_status = 'Critical' if (t == 14 and s == 2) else ('Noncritical' if (t == 2 and s == 4) else 'OK')
        if t == 2 and s == 4:
            els.append(psu(-1, 'Noncritical', off=1, ac_fail=1, dc_fail=1, dc_under_voltage=1))
        else:
            els.append(element(t, mn, -1, overall_status if t in (14,) else ('OK' if t != 23 else 'Unsupported')))
        for n in range(c):
            if t == 23:
                els.append(element(23, mn, n, 'OK', fault_sensed=0, fault_reqstd=0, ident=0,
                                   aes=slot_aes(n, 0x5000c500ca000000 + 4 * n + 1, exp_a)))
            elif t == 4:
                els.append(temp(n, 26 + n + s))
            elif t == 14:
                crit = s == 2
                els.append(encl_el(n, 'Critical' if crit else 'OK', fail_ind=1 if crit else 0))
            elif t == 3:
                els.append(fan(n, 'OK', 2500))
            elif t == 2:
                els.append(psu(n, 'Critical', off=1, ac_fail=1, dc_fail=1, dc_under_voltage=1) if s == 4 else psu(n, 'OK'))
            elif t == 25:
                els.append(element(25, mn, n, 'OK', connector_type={'i': 5, 'meaning': 'Mini SAS HD 4x receptacle (SFF-8644) [max 4 phys]'}))
            else:
                els.append(element(t, mn, n, 'OK'))
    common(d, 'ses_sg3.json|0|52\nsescfg_sg3.json|0|20\n')
    write(d, 'ses_sg3.json', ses_json(els))
    write(d, 'sescfg_sg3.json', json.dumps(cfg, indent=1) + '\n')
    write(d, 'lsscsi.txt', lsscsi_line('1:0:15:0', 'enclosu', 'EMC', 'ESES Enclosure', '0001', '-', '/dev/sg3'))
    write(d, 'scsi_hosts.txt', 'host1|mpt3sas\n')
    write(d, 'sas_phys.txt', '')
    write(d, 'lsblk.json', lsblk([]))
    write(d, 'disks.ini', '')
    write(d, 'devs.ini', '')


def main():
    if os.path.isdir(ROOT):
        shutil.rmtree(ROOT)
    it_mode()
    usb()
    e12 = ('12.0 Gbit')
    hba('hba-wide-8', {p: 0 for p in range(8)}, [(0x5003048000a1b2bf, 'SAS3x40', e12)])
    hba('hba-wide-4-c1-unused', {p: 0 for p in range(4)}, [(0x5003048000a1b2bf, 'SAS3x40', e12)])
    hba('hba-partial-c1', {p: 0 for p in range(6)}, [(0x5003048000a1b2bf, 'SAS3x40', e12)])
    hba('hba-dual-expander', {**{p: 0 for p in range(4)}, **{p: 1 for p in range(4, 8)}},
        [(0x5003048000a1b2bf, 'SAS3x28', e12), (0x5003048000a1b2ff, 'SAS3x28', e12)])
    storcli_direct()
    emc()


if __name__ == '__main__':
    main()
