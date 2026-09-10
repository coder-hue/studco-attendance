import ActivityKit
import Foundation
import Security

private enum AdminCredentialStore {
    private static let service="dev.coderhue.stucoadmin"
    static func load()->(String,String)? {
        let query:[String:Any]=[kSecClass as String:kSecClassGenericPassword,kSecAttrService as String:service,kSecReturnAttributes as String:true,kSecReturnData as String:true,kSecMatchLimit as String:kSecMatchLimitOne]
        var result:CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary,&result)==errSecSuccess,
              let item=result as? [String:Any],
              let username=item[kSecAttrAccount as String] as? String,
              let data=item[kSecValueData as String] as? Data,
              let password=String(data:data,encoding:.utf8) else{return nil}
        return(username,password)
    }
    static func save(username:String,password:String) {
        let base:[String:Any]=[kSecClass as String:kSecClassGenericPassword,kSecAttrService as String:service,kSecAttrAccount as String:username]
        SecItemDelete([kSecClass as String:kSecClassGenericPassword,kSecAttrService as String:service] as CFDictionary)
        var item=base;item[kSecValueData as String]=Data(password.utf8);SecItemAdd(item as CFDictionary,nil)
    }
}

@MainActor
final class AppModel: ObservableObject {
    @Published var user: AdminUser?
    @Published var events: [AttendanceEvent] = []
    @Published var loading = true
    @Published var error: String?
    @Published var requestedQREventID: Int?

    init() { Task { await connect() } }

    func connect() async {
        loading=true; error=nil
        defer { loading=false }
        do {
            user=try await AdminAPI.shared.session()
        } catch {
            guard let saved=AdminCredentialStore.load() else{user=nil;return}
            do { user=try await AdminAPI.shared.login(username:saved.0,password:saved.1) }
            catch { user=nil;self.error="Attendance could not connect.";return }
        }
        await loadEvents()
    }
    func login(username:String,password:String) async {
        loading=true; error=nil; defer { loading=false }
        do { user=try await AdminAPI.shared.login(username:username,password:password);AdminCredentialStore.save(username:username,password:password);await loadEvents() } catch { self.error=error.localizedDescription }
    }
    func loadEvents() async { do { events=try await AdminAPI.shared.events() } catch { self.error=error.localizedDescription } }
    func createEvent(title:String,date:String,open:String,close:String,mode:String) async throws -> Int { let id=try await AdminAPI.shared.createEvent(title:title,date:date,open:open,close:close,mode:mode); await loadEvents(); return id }
    func deleteEvent(_ id:Int) async throws {
        try await AdminAPI.shared.deleteEvent(id)
        for activity in Activity<AttendanceActivityAttributes>.activities where activity.attributes.eventID == id {
            await activity.end(nil,dismissalPolicy:.immediate)
        }
        await loadEvents()
    }

    func handleDeepLink(_ url: URL) {
        guard url.scheme == "stucoadmin", url.host == "event" else { return }
        let parts = url.pathComponents.filter { $0 != "/" }
        guard parts.count == 2, parts[1] == "qr", let eventID = Int(parts[0]) else { return }
        requestedQREventID = eventID
    }

    func updateLiveActivity(_ event: AttendanceEvent) async {
        guard ActivityAuthorizationInfo().areActivitiesEnabled else { return }
        let state=AttendanceActivityAttributes.ContentState(present:event.presentCount,total:event.memberCount,isOpen:event.isOpen || event.earlyIsOpen)
        if let activity=Activity<AttendanceActivityAttributes>.activities.first(where:{$0.attributes.eventID==event.id}) {
            await activity.update(.init(state:state,staleDate:nil)); return
        }
        for activity in Activity<AttendanceActivityAttributes>.activities { await activity.end(nil,dismissalPolicy:.immediate) }
        let attributes=AttendanceActivityAttributes(eventID:event.id,eventTitle:event.title,eventDate:event.date)
        _ = try? Activity.request(attributes:attributes,content:.init(state:state,staleDate:nil),pushType:nil)
    }
}
