import CoreImage.CIFilterBuiltins
import SwiftUI

struct AttendanceRootView: View {
    @EnvironmentObject private var model: AppModel
    var body: some View {
        Group {
            if model.loading && model.user == nil { ProgressView().controlSize(.large) }
            else if model.user == nil { AttendanceConnectionView() }
            else { EventsView() }
        }
        .tint(StuCoTheme.blue)
    }
}

private struct AttendanceConnectionView: View {
    @EnvironmentObject private var model: AppModel
    @State private var username=""
    @State private var password=""
    var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(spacing:12) {
                        Image(systemName:"person.badge.key.fill").font(.system(size:42)).foregroundStyle(StuCoTheme.blue)
                        Text("Connect attendance").font(.title2.bold())
                        Text("Sign in once. The app securely remembers this account on your phone.").foregroundStyle(.secondary).multilineTextAlignment(.center)
                    }.frame(maxWidth:.infinity).padding(.vertical,18)
                }
                Section {
                    TextField("User",text:$username).textContentType(.username).textInputAutocapitalization(.never).autocorrectionDisabled()
                    SecureField("Password",text:$password).textContentType(.password)
                    Button("Sign in") { Task { await model.login(username:username,password:password) } }.frame(maxWidth:.infinity).disabled(username.isEmpty || password.isEmpty || model.loading)
                }
                if let error=model.error { Section { Text(error).foregroundStyle(.red) } }
            }.navigationTitle("StuCo Admin")
        }
    }
}

private struct EventsView: View {
    @EnvironmentObject private var model: AppModel
    @State private var showingCreate=false
    @State private var showingSettings=false
    @State private var qrEvent:AttendanceEvent?
    var body: some View {
        NavigationStack {
            List {
                if model.events.isEmpty {
                    ContentUnavailableView("No events yet",systemImage:"calendar.badge.plus",description:Text("Create your first attendance event from this app."))
                }
                if !currentEvents.isEmpty {
                    Section("Current") { ForEach(currentEvents) { event in eventRow(event) } }
                }
                if !archivedEvents.isEmpty {
                    Section("Archived") { ForEach(archivedEvents) { event in eventRow(event) } }
                }
            }
            .navigationTitle("Attendance")
            .refreshable { await model.loadEvents() }
            .navigationDestination(for:AttendanceEvent.self) { EventDetailView(eventID:$0.id) }
            .toolbar {
                ToolbarItem(placement:.topBarLeading) { Button { showingSettings=true } label:{ Image(systemName:"gearshape") } }
                ToolbarItem(placement:.topBarTrailing) { Button { showingCreate=true } label:{ Image(systemName:"plus") } }
            }
            .sheet(isPresented:$showingCreate) { CreateEventView() }
            .sheet(isPresented:$showingSettings) { AttendanceSettingsView() }
            .sheet(item:$qrEvent) { EventQRView(event:$0) }
            .onAppear { presentRequestedQR() }
            .onChange(of:model.requestedQREventID) { _,_ in presentRequestedQR() }
            .onChange(of:model.events) { _,_ in presentRequestedQR() }
        }
    }
    private func presentRequestedQR() {
        guard let eventID=model.requestedQREventID,
              let event=model.events.first(where:{$0.id==eventID}) else{return}
        qrEvent=event
        model.requestedQREventID=nil
    }
    private func status(_ event:AttendanceEvent)->some View {
        let anyLaneOpen=event.isOpen || event.earlyIsOpen
        return Text(event.isFinalized ? "Finalized" : anyLaneOpen ? "Open" : "Closed").font(.caption.bold()).foregroundStyle(anyLaneOpen ? StuCoTheme.blue : .secondary)
    }
    private var currentEvents:[AttendanceEvent] { model.events.filter{!$0.isArchived} }
    private var archivedEvents:[AttendanceEvent] { model.events.filter(\.isArchived) }
    private func eventRow(_ event:AttendanceEvent)->some View {
        HStack(spacing:10) {
            NavigationLink(value:event) {
                VStack(alignment:.leading,spacing:8) {
                    Text(event.title).font(.headline)
                    HStack { Text(formattedDate(event.date));Text("•");Text("\(event.presentCount) checked in");Spacer();status(event) }.font(.caption).foregroundStyle(.secondary)
                    ProgressView(value:Double(event.presentCount),total:Double(max(event.memberCount,1))).tint(StuCoTheme.blue)
                }.padding(.vertical,7)
            }
            Button { qrEvent=event } label: { Image(systemName:"qrcode").font(.title2).frame(width:38,height:44) }
                .buttonStyle(.borderless).accessibilityLabel("Show QR code for \(event.title)")
        }
    }
}

private struct CreateEventView: View {
    @EnvironmentObject private var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var title=""
    @State private var date=Date()
    @State private var open=time(hour:7,minute:35)
    @State private var close=time(hour:7,minute:50)
    @State private var mode="standard"
    @State private var saving=false
    @State private var error:String?
    var body: some View {
        NavigationStack {
            Form {
                Section("Event") {
                    TextField("Event name",text:$title)
                    DatePicker("Date",selection:$date,displayedComponents:.date)
                    Picker("Attendance type",selection:$mode) {
                        Text("Regular · 2 points").tag("standard")
                        Text("Decorations Day · 6 or 8 points").tag("decorations")
                        Text("Dance shifts · 2 points").tag("dance_shifts")
                    }
                }
                Section("Backup schedule") {
                    DatePicker("Opens",selection:$open,displayedComponents:.hourAndMinute)
                    DatePicker("Closes",selection:$close,displayedComponents:.hourAndMinute)
                    Text("You can manually open or close check-in at any time.").font(.footnote).foregroundStyle(.secondary)
                }
                if let error { Text(error).foregroundStyle(.red) }
                Section { Button("Create Event") { Task { await create() } }.frame(maxWidth:.infinity).disabled(saving || title.trimmingCharacters(in:.whitespacesAndNewlines).isEmpty) }
            }
            .navigationTitle("New Event").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement:.cancellationAction) { Button("Cancel") { dismiss() } } }
        }
    }
    private func create() async {
        saving=true; defer { saving=false }
        let day=DateFormatter.apiDay.string(from:date),start=DateFormatter.apiTime.string(from:open),end=DateFormatter.apiTime.string(from:close)
        do { _=try await model.createEvent(title:title.trimmingCharacters(in:.whitespacesAndNewlines),date:day,open:start,close:end,mode:mode); dismiss() } catch { self.error=error.localizedDescription }
    }
    private static func time(hour:Int,minute:Int)->Date { Calendar.current.date(bySettingHour:hour,minute:minute,second:0,of:Date())! }
}

private struct EventDetailView: View {
    @EnvironmentObject private var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let eventID:Int
    @State private var response:EventResponse?
    @State private var error:String?
    @State private var working=false
    @State private var showingEdit=false
    @State private var confirmingDelete=false
    @State private var showingQR=false
    @State private var showingEarlyQR=false
    @State private var selectedMember:RosterMember?
    var body: some View {
        Group {
            if let response { detail(response) }
            else if let error { ContentUnavailableView("Could not load attendance",systemImage:"exclamationmark.triangle",description:Text(error)) }
            else { ProgressView() }
        }
        .navigationTitle("Event").navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement:.topBarTrailing) {
                Menu {
                    Button("Edit Attendance",systemImage:"pencil") { showingEdit=true }
                    Button("Delete Attendance",systemImage:"trash",role:.destructive) { confirmingDelete=true }
                } label: { Image(systemName:"ellipsis.circle") }
            }
        }
        .sheet(isPresented:$showingEdit) {
            if let event=response?.event { EditEventView(event:event) { response=$0 } }
        }
        .sheet(isPresented:$showingQR) { if let event=response?.event { EventQRView(event:event) } }
        .sheet(isPresented:$showingEarlyQR) { if let event=response?.event { EventQRView(event:event,early:true) } }
        .alert("Delete attendance?",isPresented:$confirmingDelete) {
            Button("Delete",role:.destructive) { Task { await deleteEvent() } }
            Button("Cancel",role:.cancel) {}
        } message: { Text("This permanently deletes the event and every recorded check-in.") }
        .confirmationDialog(selectedMember?.name ?? "Update attendance",isPresented:memberActionsPresented,titleVisibility:.visible) {
            if let member=selectedMember {
                if response?.event.attendanceMode == "decorations" {
                    Button("Full time · 8 points") { Task { await setAttendance(member,points:8) } }
                    Button("Left early · 6 points") { Task { await setAttendance(member,points:6) } }
                    if member.status=="present" { Button("Remove Check-in",role:.destructive) { Task { await setAttendance(member,points:nil) } } }
                } else if member.status=="present" {
                    Button("Remove Check-in",role:.destructive) { Task { await setAttendance(member,points:nil) } }
                } else {
                    Button("Mark Present") { Task { await setAttendance(member,points:2) } }
                }
            }
            Button("Cancel",role:.cancel) { selectedMember=nil }
        } message: { Text("This changes the member’s attendance immediately.") }
        .task { await refreshLoop() }
    }
    private func detail(_ result:EventResponse)->some View {
        List {
            Section {
                VStack(alignment:.leading,spacing:8) {
                    Text(result.event.title).font(.title2.bold())
                    Text("\(formattedDate(result.event.date)) • \(result.event.startTime)–\(result.event.endTime)").font(.subheadline).foregroundStyle(.secondary)
                }.padding(.vertical,6)
            }
            Section {
                HStack(spacing:0) {
                    metric("Present",result.event.presentCount)
                    Divider().frame(height:52)
                    metric("Missing",max(0,result.event.memberCount-result.event.presentCount))
                    Divider().frame(height:52)
                    metric("Members",result.event.memberCount)
                }.listRowInsets(EdgeInsets(top:16,leading:8,bottom:16,trailing:8))
            }
            if result.event.attendanceMode == "decorations" {
                decorationsLane(title:"Left early",points:6,url:result.event.earlyCheckinURL,isOpen:result.event.earlyIsOpen,early:true)
                decorationsLane(title:"Full time",points:8,url:result.event.checkinURL,isOpen:result.event.isOpen,early:false)
            } else if let value=result.event.checkinURL,let url=URL(string:value) {
                Section("Event QR") {
                    Button { showingQR=true } label: { QRCodeView(value:value,size:220).frame(maxWidth:.infinity) }.buttonStyle(.plain).listRowInsets(EdgeInsets(top:18,leading:18,bottom:18,trailing:18))
                    Button("Show Full-Screen QR",systemImage:"arrow.up.left.and.arrow.down.right") { showingQR=true }
                    ShareLink(item:url) { Label("Share check-in link",systemImage:"square.and.arrow.up") }
                }
            }
            Section {
                if result.event.attendanceMode != "decorations" {
                    Button(result.event.isOpen ? "Close Check-in" : "Open Check-in") { Task { await toggle(lane:"full") } }.disabled(working || result.event.isFinalized)
                    if result.event.checkinMode != "scheduled" && !result.event.isFinalized {
                        Button("Use Backup Schedule",systemImage:"clock.arrow.circlepath") { Task { await useSchedule(lane:"full") } }.disabled(working)
                    }
                }
                if !result.event.isFinalized { Button("Finalize Attendance",role:.destructive) { Task { await finalize() } }.disabled(working) }
            }
            Section("Members") {
                ForEach(result.roster) { member in
                    Button { selectedMember=member } label: {
                        HStack {
                            VStack(alignment:.leading,spacing:3) { Text(member.name).foregroundStyle(.primary); if let time=member.checkInTime { Text(time,style:.time).font(.caption).foregroundStyle(.secondary) } }
                            Spacer()
                            if let points=member.points { Text("\(points) pts").font(.caption.bold()).foregroundStyle(StuCoTheme.blue) }
                            Image(systemName:member.status=="present" ? "checkmark.circle.fill" : "minus.circle").foregroundStyle(member.status=="present" ? StuCoTheme.blue : .secondary)
                            Image(systemName:"ellipsis.circle").foregroundStyle(.secondary)
                        }
                    }.buttonStyle(.plain).disabled(working)
                }
            }
        }
    }
    @ViewBuilder private func decorationsLane(title:String,points:Int,url:String?,isOpen:Bool,early:Bool)->some View {
        Section("\(title) · \(points) points") {
            if let value=url,let shareURL=URL(string:value) {
                Button { if early { showingEarlyQR=true } else { showingQR=true } } label: { QRCodeView(value:value,size:190).frame(maxWidth:.infinity) }.buttonStyle(.plain)
                ShareLink(item:shareURL) { Label("Share \(title.lowercased()) link",systemImage:"square.and.arrow.up") }
            }
            Button(isOpen ? "Close \(title)" : "Open \(title)") { Task { await toggle(lane:early ? "early":"full") } }.disabled(working)
            Button("Use Backup Schedule",systemImage:"clock.arrow.circlepath") { Task { await useSchedule(lane:early ? "early":"full") } }.disabled(working)
        }
    }
    private var memberActionsPresented:Binding<Bool> { Binding(get:{selectedMember != nil},set:{if !$0{selectedMember=nil}}) }
    private func metric(_ label:String,_ number:Int)->some View { VStack(spacing:4) { Text("\(number)").font(.title.bold()).foregroundStyle(StuCoTheme.blue); Text(label).font(.caption).foregroundStyle(.secondary) }.frame(maxWidth:.infinity) }
    private func load() async { do { let result=try await AdminAPI.shared.event(eventID); response=result; await model.updateLiveActivity(result.event); error=nil } catch { self.error=error.localizedDescription } }
    private func refreshLoop() async { while !Task.isCancelled { await load(); try? await Task.sleep(for:.seconds(5)) } }
    private func toggle(lane:String) async { guard let event=response?.event else{return};let open=lane=="early" ? event.earlyIsOpen:event.isOpen;working=true; defer { working=false }; do { response=try await AdminAPI.shared.setEventState(eventID,state:open ? "closed":"open",lane:lane);await model.loadEvents() } catch { self.error=error.localizedDescription } }
    private func useSchedule(lane:String) async { working=true; defer { working=false }; do { response=try await AdminAPI.shared.setEventState(eventID,state:"scheduled",lane:lane);await model.loadEvents() } catch { self.error=error.localizedDescription } }
    private func finalize() async { working=true; defer { working=false }; do { response=try await AdminAPI.shared.finalizeEvent(eventID) } catch { self.error=error.localizedDescription } }
    private func setAttendance(_ member:RosterMember,points:Int?) async { working=true;defer{working=false;selectedMember=nil};do{let updated=try await AdminAPI.shared.setAttendance(eventID:eventID,memberID:member.id,points:points);response=updated;await model.loadEvents();await model.updateLiveActivity(updated.event)}catch{self.error=error.localizedDescription} }
    private func deleteEvent() async { working=true;defer{working=false};do{try await model.deleteEvent(eventID);dismiss()}catch{self.error=error.localizedDescription} }
}

private struct EditEventView:View {
    @EnvironmentObject private var model:AppModel
    @Environment(\.dismiss) private var dismiss
    let event:AttendanceEvent
    let onSaved:(EventResponse)->Void
    @State private var title:String
    @State private var date:Date
    @State private var open:Date
    @State private var close:Date
    @State private var saving=false
    @State private var error:String?
    init(event:AttendanceEvent,onSaved:@escaping(EventResponse)->Void){self.event=event;self.onSaved=onSaved;_title=State(initialValue:event.title);_date=State(initialValue:DateFormatter.apiDay.date(from:event.date) ?? Date());_open=State(initialValue:DateFormatter.apiDateTime.date(from:"\(event.date) \(event.startTime)") ?? Date());_close=State(initialValue:DateFormatter.apiDateTime.date(from:"\(event.date) \(event.endTime)") ?? Date())}
    var body:some View {
        NavigationStack { Form {
            Section("Event") { TextField("Event name",text:$title);DatePicker("Date",selection:$date,displayedComponents:.date) }
            Section("Backup schedule") { DatePicker("Opens",selection:$open,displayedComponents:.hourAndMinute);DatePicker("Closes",selection:$close,displayedComponents:.hourAndMinute) }
            if let error { Text(error).foregroundStyle(.red) }
        }.navigationTitle("Edit Attendance").navigationBarTitleDisplayMode(.inline).toolbar {
            ToolbarItem(placement:.cancellationAction){Button("Cancel"){dismiss()}}
            ToolbarItem(placement:.confirmationAction){Button("Save"){Task{await save()}}.disabled(saving || title.trimmingCharacters(in:.whitespacesAndNewlines).isEmpty)}
        } }
    }
    private func save()async{saving=true;defer{saving=false};do{let updated=try await AdminAPI.shared.updateEvent(event.id,title:title.trimmingCharacters(in:.whitespacesAndNewlines),date:DateFormatter.apiDay.string(from:date),open:DateFormatter.apiTime.string(from:open),close:DateFormatter.apiTime.string(from:close));await model.loadEvents();onSaved(updated);dismiss()}catch{self.error=error.localizedDescription}}
}

private struct EventQRView:View {
    @Environment(\.dismiss) private var dismiss
    let event:AttendanceEvent
    var early=false
    private var qrURL:String? { early ? event.earlyCheckinURL:event.checkinURL }
    private var laneOpen:Bool { early ? event.earlyIsOpen:event.isOpen }
    private var laneTitle:String { early ? "Left early · 6 points":(event.attendanceMode == "decorations" ? "Full time · 8 points":"Check-in") }
    var body:some View {
        NavigationStack {
            GeometryReader { proxy in
                let qrSize=max(220,min(proxy.size.width-48,520))
                ScrollView {
                    VStack(spacing:18) {
                        VStack(spacing:6) {
                            Text(event.title).font(.title.bold()).multilineTextAlignment(.center)
                            Text(laneTitle).font(.title3.bold()).foregroundStyle(StuCoTheme.blue)
                            Text(formattedDate(event.date)).font(.headline).foregroundStyle(.secondary)
                        }
                        if let value=qrURL,let url=URL(string:value) {
                            QRCodeView(value:value,size:qrSize-28)
                                .clipShape(RoundedRectangle(cornerRadius:18))
                            Label(laneOpen ? "Check-in is open":"Check-in is closed",systemImage:laneOpen ? "checkmark.circle.fill":"xmark.circle.fill")
                                .font(.headline).foregroundStyle(laneOpen ? Color.green:Color.red)
                            ShareLink(item:url) { Label("Share Check-in Link",systemImage:"square.and.arrow.up").frame(maxWidth:.infinity) }
                                .buttonStyle(.borderedProminent).controlSize(.large)
                        } else {
                            ContentUnavailableView("QR unavailable",systemImage:"qrcode",description:Text("Refresh the event and try again."))
                        }
                    }
                    .padding(24).frame(maxWidth:.infinity)
                }
            }
            .navigationTitle(laneTitle).navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement:.confirmationAction){Button("Done"){dismiss()}} }
        }
        .onAppear { UIApplication.shared.isIdleTimerDisabled=true }
        .onDisappear { UIApplication.shared.isIdleTimerDisabled=false }
    }
}

private struct AttendanceSettingsView:View {
    @EnvironmentObject private var lock:AppLockModel
    @EnvironmentObject private var model:AppModel
    @Environment(\.dismiss) private var dismiss
    @AppStorage("stucoAppearance") private var appearance="system"
    var body:some View {
        NavigationStack { Form {
            Section("Appearance") {
                Picker("Theme",selection:$appearance){Text("System").tag("system");Text("Light").tag("light");Text("Dark").tag("dark")}.pickerStyle(.segmented)
            }
            Section("Connection") { LabeledContent("Attendance server",value:model.user == nil ? "Offline":"Connected") }
            Section("Security") { Button("Lock App",systemImage:"lock.fill"){dismiss();lock.lock()} }
        }.navigationTitle("Settings").navigationBarTitleDisplayMode(.inline).toolbar { ToolbarItem(placement:.confirmationAction){Button("Done"){dismiss()}} } }
    }
}

private struct QRCodeView: View {
    let value:String
    let size:CGFloat
    var body: some View { if let image=qrImage(value) { Image(uiImage:image).interpolation(.none).resizable().scaledToFit().frame(width:size,height:size).padding(14).background(.white) } }
    private func qrImage(_ value:String)->UIImage? { let filter=CIFilter.qrCodeGenerator(); filter.message=Data(value.utf8); filter.correctionLevel="M"; guard let output=filter.outputImage else{return nil}; let context=CIContext(); guard let cg=context.createCGImage(output.transformed(by:CGAffineTransform(scaleX:12,y:12)),from:output.extent.applying(CGAffineTransform(scaleX:12,y:12))) else{return nil}; return UIImage(cgImage:cg) }
}

private func formattedDate(_ value:String)->String { DateFormatter.apiDay.date(from:value)?.formatted(date:.abbreviated,time:.omitted) ?? value }
private extension DateFormatter {
    static let apiDay:DateFormatter={let f=DateFormatter();f.locale=Locale(identifier:"en_US_POSIX");f.dateFormat="yyyy-MM-dd";return f}()
    static let apiTime:DateFormatter={let f=DateFormatter();f.locale=Locale(identifier:"en_US_POSIX");f.dateFormat="HH:mm";return f}()
    static let apiDateTime:DateFormatter={let f=DateFormatter();f.locale=Locale(identifier:"en_US_POSIX");f.dateFormat="yyyy-MM-dd HH:mm";return f}()
}
